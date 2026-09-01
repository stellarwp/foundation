<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

use InvalidArgumentException;
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
	/**
	 * @throws InvalidArgumentException When the unprefixed table name is blank or padded.
	 */
	public function __construct(
		private string $unprefixedTableName,
		private Database $database
	) {
		if ($this->unprefixedTableName === '' || trim($this->unprefixedTableName) !== $this->unprefixedTableName) {
			throw new InvalidArgumentException('The unprefixed database table name cannot be blank or contain surrounding whitespace.');
		}
	}

	/**
	 * Resolve the physical table name for the active WordPress database scope.
	 *
	 * @throws DatabaseException When the resolved physical table name is invalid.
	 */
	final public function name(): string {
		return $this->database->tableName($this->unprefixedTableName);
	}

	/**
	 * Begin a query against this table.
	 */
	final public function query(?string $alias = null): QueryBuilder {
		return $this->database->table($this->unprefixedTableName, $alias);
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws DatabaseException When the resolved physical table name is invalid.
	 * @throws QueryException    When the insert fails.
	 */
	final public function insert(array $data): int {
		return $this->database->insert($this->unprefixedTableName, $data);
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws DatabaseException When the resolved physical table name is invalid.
	 * @throws QueryException    When the insert fails.
	 */
	final public function insertGetId(array $data): int {
		return $this->database->insertGetId($this->unprefixedTableName, $data);
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 *
	 * @throws DatabaseException When the resolved physical table name is invalid.
	 * @throws QueryException    When the update fails.
	 */
	final public function update(array $data, array $where): int {
		return $this->database->update($this->unprefixedTableName, $data, $where);
	}

	/**
	 * @param array<string, mixed> $where
	 *
	 * @throws DatabaseException When the resolved physical table name is invalid.
	 * @throws QueryException    When the delete fails.
	 */
	final public function delete(array $where): int {
		return $this->database->delete($this->unprefixedTableName, $where);
	}
}
