<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\QueryException;

/**
 * Executes WordPress database queries and provides the package's developer-facing database API.
 */
interface Database
{
	/**
	 * Resolve and validate a table's physical name for the active WordPress database scope.
	 *
	 * @throws DatabaseException When the unprefixed name is invalid or the physical name is unsafe or exceeds MySQL's identifier limit.
	 */
	public function tableName(Table $table): string;

	/**
	 * @throws DatabaseException When table inspection fails.
	 */
	public function tableExists(Table $table): bool;

	/**
	 * @throws DatabaseException When column inspection fails.
	 */
	public function columnExists(Table $table, string $column): bool;

	/**
	 * @throws DatabaseException When index inspection fails.
	 */
	public function indexExists(Table $table, string $index): bool;

	/**
	 * @throws QueryException When WordPress cannot prepare the statement.
	 */
	public function prepare(string $sql, mixed ...$bindings): string;

	/**
	 * @throws QueryException When the query fails.
	 *
	 * @return array<string, mixed>|null
	 */
	public function row(string $sql, mixed ...$bindings): ?array;

	/**
	 * @throws QueryException When the query fails.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function rows(string $sql, mixed ...$bindings): array;

	/**
	 * @throws QueryException When the query fails.
	 */
	public function value(string $sql, mixed ...$bindings): mixed;

	/**
	 * @throws QueryException When the statement fails.
	 */
	public function execute(string $sql, mixed ...$bindings): int;

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws DatabaseException When table-name resolution or validation fails.
	 * @throws QueryException    When the insert fails.
	 *
	 * @return int Number of inserted rows.
	 */
	public function insert(Table $table, array $data): int;

	/**
	 * Insert a row and return its auto-increment identifier.
	 *
	 * @param array<string, mixed> $data
	 *
	 * @throws DatabaseException When table-name resolution or validation fails.
	 * @throws QueryException    When the insert fails.
	 */
	public function insertGetId(Table $table, array $data): int;

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 *
	 * @throws DatabaseException When table-name resolution or validation fails.
	 * @throws QueryException    When the update fails.
	 */
	public function update(Table $table, array $data, array $where): int;

	/**
	 * @param array<string, mixed> $where
	 *
	 * @throws DatabaseException When table-name resolution or validation fails.
	 * @throws QueryException    When the delete fails.
	 */
	public function delete(Table $table, array $where): int;

	public function quoteIdentifier(string $identifier): string;

	public function escLike(string $value): string;

	public function charsetCollate(): string;
}
