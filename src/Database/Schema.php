<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database;

use Closure;
use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\Schema as SchemaContract;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * WordPress schema operations backed by wpdb and dbDelta.
 */
final readonly class Schema implements SchemaContract
{
	/**
	 * @param Closure(string): mixed|null $dbDelta
	 */
	public function __construct(
		private Database $database,
		private ?Closure $dbDelta = null
	) {
	}

	public function createOrUpdate(Table|string $table, ?string $sql = null): void {
		$dbDelta = $this->dbDelta ?? $this->loadDbDelta();

		$dbDelta($sql ?? $this->createTableSql($table));
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

	private function createTableSql(Table|string $table): string {
		if (is_string($table)) {
			return $table;
		}

		$definition = $table->definition();
		$definition->assertValid();

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

	/**
	 * @return Closure(string): mixed
	 */
	private function loadDbDelta(): Closure {
		if (! function_exists('dbDelta') && defined('ABSPATH')) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		if (! function_exists('dbDelta')) {
			throw new DatabaseException('WordPress dbDelta() is not available.');
		}

		return dbDelta(...);
	}
}
