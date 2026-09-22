<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Table as TableContract;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * An application table with ordinary reads and writes on the shared connection.
 *
 * Subclasses inherit construction and declare their stable unprefixed name.
 */
abstract readonly class Table implements TableContract
{
	/**
	 * Receive the application's shared connection and table-name policy.
	 */
	public function __construct(
		private Connection $connection,
		private TableNameResolver $names,
	) {
	}

	/**
	 * Return the stable name before WordPress applies its site prefix.
	 */
	abstract public function unprefixedName(): string;

	/**
	 * Resolve this table's physical name at the current site.
	 *
	 * @throws DatabaseException When the physical name is invalid.
	 *
	 * @return non-empty-string
	 */
	final public function name(): string {
		return $this->names->tableName($this);
	}

	/**
	 * Quote the resolved name for native Doctrine expressions and SQL.
	 *
	 * @throws DatabaseException When the physical name is invalid.
	 * @throws Exception         When the platform cannot be determined.
	 */
	final public function quotedName(): string {
		return $this->connection->getDatabasePlatform()->quoteSingleIdentifier($this->name());
	}

	/**
	 * Start a fresh native query with this table as its source.
	 *
	 * @throws DatabaseException When the physical name is invalid.
	 * @throws Exception         When the platform cannot be determined.
	 */
	final public function query(?string $alias = null): QueryBuilder {
		return $this->connection->createQueryBuilder()->from($this->quotedName(), $alias);
	}

	/**
	 * Insert a row and return its affected-row count.
	 *
	 * @param array<string, mixed>                     $data  Application-owned column names and bound values.
	 * @param array<string, string|ParameterType|Type> $types
	 *
	 * @throws DatabaseException When the physical name is invalid.
	 * @throws Exception         When insertion fails.
	 */
	final public function insert(array $data, array $types = []): int|string {
		return $this->connection->insert($this->quotedName(), $data, $types);
	}

	/**
	 * Insert a row and return its generated identifier without truncation.
	 *
	 * @param array<string, mixed>                     $data
	 * @param array<string, string|ParameterType|Type> $types
	 *
	 * @throws DatabaseException When the physical name is invalid.
	 * @throws Exception         When insertion fails or no generated identifier is available.
	 */
	final public function insertGetId(array $data, array $types = []): int|string {
		$this->insert($data, $types);

		return $this->connection->lastInsertId();
	}

	/**
	 * Update rows matching equality criteria, returning the affected-row count.
	 *
	 * @param array<string, mixed>                     $data
	 * @param array<string, mixed>                     $where
	 * @param array<string, string|ParameterType|Type> $types
	 *
	 * @throws InvalidArgumentException When criteria are empty.
	 * @throws DatabaseException        When the physical name is invalid.
	 * @throws Exception                When the update fails.
	 */
	final public function update(array $data, array $where, array $types = []): int|string {
		if ($where === []) {
			throw new InvalidArgumentException('Table updates require criteria; use the connection for an intentional whole-table update.');
		}

		return $this->connection->update($this->quotedName(), $data, $where, $types);
	}

	/**
	 * Delete rows matching equality criteria, returning the affected-row count.
	 *
	 * @param array<string, mixed>                     $where
	 * @param array<string, string|ParameterType|Type> $types
	 *
	 * @throws InvalidArgumentException When criteria are empty.
	 * @throws DatabaseException        When the physical name is invalid.
	 * @throws Exception                When deletion fails.
	 */
	final public function delete(array $where, array $types = []): int|string {
		if ($where === []) {
			throw new InvalidArgumentException('Table deletions require criteria; use the connection for an intentional whole-table delete.');
		}

		return $this->connection->delete($this->quotedName(), $where, $types);
	}
}
