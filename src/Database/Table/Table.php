<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\Table as TableContract;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\QueryException;
use StellarWP\Foundation\Database\Query\QueryBuilder;

/**
 * Provides a database gateway bound to one unprefixed WordPress table name.
 *
 * Extend this class for normal application tables. Implement {@see TableContract}
 * directly only when a custom table implementation does not need these operations.
 */
abstract readonly class Table implements TableContract
{
	public function __construct(
		private string $unprefixedTableName,
		private Database $database
	) {
	}

	/**
	 * Return the database service for custom operations scoped to this table.
	 */
	final protected function database(): Database {
		return $this->database;
	}

	/**
	 * Return the stable table name before WordPress scope is applied.
	 */
	final public function unprefixedName(): string {
		return $this->unprefixedTableName;
	}

	/**
	 * Resolve a validated physical table name for the active WordPress database scope.
	 *
	 * Prefixing and MySQL identifier-length validation are owned by the database
	 * name-resolution boundary so callers can rely on the returned name.
	 *
	 * @throws DatabaseException When the resolved physical table name is invalid.
	 */
	final public function name(): string {
		return $this->database->tableName($this);
	}

	/**
	 * Begin a query against this table.
	 */
	final public function query(?string $alias = null): QueryBuilder {
		return new QueryBuilder($this->database, $this, $alias);
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws DatabaseException When the resolved physical table name is invalid.
	 * @throws QueryException    When the insert fails.
	 */
	final public function insert(array $data): int {
		return $this->database->insert($this, $data);
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws DatabaseException When the resolved physical table name is invalid.
	 * @throws QueryException    When the insert fails.
	 */
	final public function insertGetId(array $data): int {
		return $this->database->insertGetId($this, $data);
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 *
	 * @throws DatabaseException When the resolved physical table name is invalid.
	 * @throws QueryException    When the update fails.
	 */
	final public function update(array $data, array $where): int {
		return $this->database->update($this, $data, $where);
	}

	/**
	 * @param array<string, mixed> $where
	 *
	 * @throws DatabaseException When the resolved physical table name is invalid.
	 * @throws QueryException    When the delete fails.
	 */
	final public function delete(array $where): int {
		return $this->database->delete($this, $where);
	}
}
