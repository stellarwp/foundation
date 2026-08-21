<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\Schema as SchemaContract;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Schema\Reconciler;

/**
 * WordPress schema operations backed by wpdb and dbDelta.
 */
final readonly class Schema implements SchemaContract
{
	public function __construct(
		private Database $database,
		private Reconciler $reconciler
	) {
	}

	/**
	 * @throws DatabaseException        When WordPress cannot reconcile the table definition.
	 * @throws InvalidArgumentException When the table definition is invalid.
	 */
	public function createOrUpdate(Table $table): void {
		$this->reconciler->reconcile($table);
	}

	public function execute(string $sql): void {
		$this->database->execute($sql);
	}

	/**
	 * @throws DatabaseException When table inspection fails.
	 */
	public function hasTable(Table|string $table): bool {
		return $this->database->tableExists($table);
	}

	/**
	 * @throws DatabaseException When index inspection fails.
	 */
	public function hasIndex(Table|string $table, string $index): bool {
		return $this->database->indexExists($table, $index);
	}

	/**
	 * @throws DatabaseException When the table name is invalid or the statement cannot be executed.
	 */
	public function dropIndex(Table|string $table, string $index): void {
		$this->database->execute(sprintf(
			'ALTER TABLE %s DROP INDEX %s',
			$this->database->quoteIdentifier($this->database->tableName($table)),
			$this->database->quoteIdentifier($index)
		));
	}

	/**
	 * @throws DatabaseException When the table name is invalid or the statement cannot be executed.
	 */
	public function drop(Table|string $table): void {
		$this->database->execute(sprintf(
			'DROP TABLE IF EXISTS %s',
			$this->database->quoteIdentifier($this->database->tableName($table))
		));
	}

	public function quoteIdentifier(string $identifier): string {
		return $this->database->quoteIdentifier($identifier);
	}
}
