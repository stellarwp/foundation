<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database;

use Closure;
use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\Schema as SchemaContract;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * WordPress schema operations backed by wpdb and dbDelta.
 */
final readonly class Schema implements SchemaContract
{
	/**
	 * @param Closure(string, bool): array<string, string> $dbDelta
	 */
	public function __construct(
		private Database $database,
		private Closure $dbDelta
	) {
	}

	/**
	 * @throws DatabaseException When WordPress cannot reconcile the table definition.
	 */
	public function createOrUpdate(Table $table): void {
		$definition = $table->definition();
		$definition->assertValid();

		$this->applyDelta($this->createTableSql($table, $definition));
		$this->reconcileComplexDefaults($table, $definition);
	}

	/**
	 * @throws DatabaseException When WordPress cannot reconcile the SQL definition.
	 */
	public function createOrUpdateSql(string $sql): void {
		$this->applyDelta($sql);
	}

	public function execute(string $sql): void {
		$this->database->execute($sql);
	}

	public function hasTable(Table|string $table): bool {
		return $this->database->tableExists($table);
	}

	public function hasIndex(Table|string $table, string $index): bool {
		return $this->database->indexExists($table, $index);
	}

	public function dropIndex(Table|string $table, string $index): void {
		$this->database->execute(sprintf(
			'ALTER TABLE %s DROP INDEX %s',
			$this->database->quoteIdentifier($this->database->tableName($table)),
			$this->database->quoteIdentifier($index)
		));
	}

	public function drop(Table|string $table): void {
		$this->database->execute(sprintf(
			'DROP TABLE IF EXISTS %s',
			$this->database->quoteIdentifier($this->database->tableName($table))
		));
	}

	public function quoteIdentifier(string $identifier): string {
		return $this->database->quoteIdentifier($identifier);
	}

	private function createTableSql(Table $table, TableDefinition $definition): string {
		$parts = [];

		foreach ($definition->columns() as $column) {
			$parts[] = '  ' . $column->sql();
		}

		foreach ($definition->indexes() as $index) {
			$parts[] = '  ' . $index->sql();
		}

		return sprintf(
			"CREATE TABLE %s (\n%s\n) %s;",
			$this->database->quoteIdentifier($table->name()),
			implode(",\n", $parts),
			$this->database->charsetCollate()
		);
	}

	private function reconcileComplexDefaults(Table $table, TableDefinition $definition): void {
		foreach ($definition->columns() as $column) {
			$default = $column->defaultSql();

			if ($default === null || ! str_starts_with($default, "X'")) {
				continue;
			}

			$this->database->execute(sprintf(
				'ALTER TABLE %s ALTER COLUMN %s SET DEFAULT %s',
				$this->database->quoteIdentifier($table->name()),
				$this->database->quoteIdentifier($column->name),
				$default
			));
		}
	}

	private function applyDelta(string $sql): void {
		($this->dbDelta)($sql, true);
		$pending = ($this->dbDelta)($sql, false);

		if ($pending !== []) {
			throw new DatabaseException(sprintf(
				'Database schema reconciliation did not complete: %s',
				implode('; ', $pending)
			));
		}
	}
}
