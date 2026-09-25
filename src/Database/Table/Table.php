<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Table as TableContract;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Query\Database;
use StellarWP\Foundation\Database\Query\Query;

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
		private TableNameResolver $resolver,
		private Database $db,
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
		return $this->resolver->tableName($this);
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
	 * Start a fresh fluent query with this table as its source.
	 *
	 * @throws DatabaseException When the physical name is invalid.
	 * @throws Exception         When the platform cannot be determined.
	 */
	final public function query(?string $alias = null): Query {
		return $this->db->table($this, $alias);
	}

	/**
	 * Count all rows in this table at the current site.
	 *
	 * @throws DatabaseException When the physical name or managed session is invalid.
	 * @throws Exception         When the count cannot be read.
	 */
	final public function count(): int {
		return $this->query()->count();
	}

	/**
	 * Insert one row or a list of rows and return the total affected-row count.
	 *
	 * Empty input performs no write. Use a caller-owned transaction when a bulk
	 * insert must be atomic across multiple statements.
	 *
	 * @param array<string, mixed>|list<array<string, mixed>> $rows
	 *
	 * @throws InvalidArgumentException When columns, values, or row shapes are invalid.
	 * @throws DatabaseException        When the physical name or managed session is invalid.
	 * @throws Exception                When insertion fails, including in a later chunk.
	 */
	final public function insert(array $rows): int {
		return $this->query()->insert($rows);
	}

	/**
	 * Insert exactly one row and return its generated identifier without truncation.
	 *
	 * Empty data inserts one row using the database defaults.
	 *
	 * @param array<string, mixed> $data
	 *
	 * @throws InvalidArgumentException When data is a row list or contains invalid columns or values.
	 * @throws DatabaseException        When the physical name or managed session is invalid.
	 * @throws Exception                When insertion fails or no generated identifier is available.
	 */
	final public function insertGetId(array $data): int|string {
		return $this->query()->insertGetId($data);
	}

	/**
	 * Update rows matching equality criteria, returning the affected-row count.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 *
	 * @throws InvalidArgumentException When criteria are empty or columns and values are invalid.
	 * @throws DatabaseException        When the physical name is invalid.
	 * @throws Exception                When the update fails.
	 */
	final public function update(array $data, array $where): int|string {
		if ($where === []) {
			throw new InvalidArgumentException('Table updates require criteria; use the connection for an intentional whole-table update.');
		}

		return $this->query()->where($where)->update($data);
	}

	/**
	 * Delete rows matching equality criteria, returning the affected-row count.
	 *
	 * @param array<string, mixed> $where
	 *
	 * @throws InvalidArgumentException When criteria are empty or columns and values are invalid.
	 * @throws DatabaseException        When the physical name is invalid.
	 * @throws Exception                When deletion fails.
	 */
	final public function delete(array $where): int|string {
		if ($where === []) {
			throw new InvalidArgumentException('Table deletions require criteria; use deleteAll() for an intentional whole-table delete.');
		}

		return $this->query()->where($where)->delete();
	}

	/**
	 * Delete all rows, returning the affected-row count and preserving foreign-key enforcement.
	 *
	 * Participates in the caller's transaction and does not reset auto-increment.
	 *
	 * @throws DatabaseException When the physical name or managed session is invalid.
	 * @throws Exception         When deletion fails, including a missing table or foreign-key restriction.
	 */
	final public function deleteAll(): int|string {
		return $this->connection->executeStatement('DELETE FROM ' . $this->quotedName());
	}

	/**
	 * Truncate this table and reset auto-increment outside a transaction.
	 *
	 * Truncation implicitly commits and cannot be rolled back. Foreign-key checks remain enabled.
	 *
	 * @throws DatabaseException When a transaction is active or the physical name or managed session is invalid.
	 * @throws Exception         When truncation fails, including a missing table or foreign-key restriction.
	 */
	final public function truncate(): void {
		if ($this->connection->isTransactionActive()) {
			throw new DatabaseException('Table truncation cannot run inside a transaction; use deleteAll() instead.');
		}

		$sql = $this->connection->getDatabasePlatform()->getTruncateTableSQL($this->quotedName());
		$this->connection->executeStatement($sql);
	}
}
