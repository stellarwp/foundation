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
	 * Determine whether a table exists in the active database scope.
	 *
	 * @throws DatabaseException When table inspection fails.
	 */
	public function tableExists(Table $table): bool;

	/**
	 * Determine whether a named column exists on a table.
	 *
	 * @throws DatabaseException When column inspection fails.
	 */
	public function columnExists(Table $table, string $column): bool;

	/**
	 * Determine whether a named index exists on a table.
	 *
	 * @throws DatabaseException When index inspection fails.
	 */
	public function indexExists(Table $table, string $index): bool;

	/**
	 * Prepare a SQL template using WordPress placeholder bindings.
	 *
	 * @throws QueryException When WordPress cannot prepare the statement.
	 */
	public function prepare(string $sql, mixed ...$bindings): string;

	/**
	 * Execute a query and return its first row when present.
	 *
	 * @throws QueryException When the query fails.
	 *
	 * @return array<string, mixed>|null
	 */
	public function row(string $sql, mixed ...$bindings): ?array;

	/**
	 * Execute a query and return every matching row.
	 *
	 * @throws QueryException When the query fails.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function rows(string $sql, mixed ...$bindings): array;

	/**
	 * Execute a query and return the first column from its first row.
	 *
	 * @throws QueryException When the query fails.
	 */
	public function value(string $sql, mixed ...$bindings): mixed;

	/**
	 * Execute a write or schema statement and return its affected-row count.
	 *
	 * @throws QueryException When the statement fails.
	 */
	public function execute(string $sql, mixed ...$bindings): int;

	/**
	 * Insert one row into the supplied table.
	 *
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
	 * Update rows matching equality-based column values.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 *
	 * @throws DatabaseException When table-name resolution or validation fails.
	 * @throws QueryException    When the update fails.
	 */
	public function update(Table $table, array $data, array $where): int;

	/**
	 * Delete rows matching equality-based column values.
	 *
	 * @param array<string, mixed> $where
	 *
	 * @throws DatabaseException When table-name resolution or validation fails.
	 * @throws QueryException    When the delete fails.
	 */
	public function delete(Table $table, array $where): int;

	/**
	 * Quote one trusted SQL identifier while escaping embedded backticks.
	 */
	public function quoteIdentifier(string $identifier): string;

	/**
	 * Escape SQL LIKE wildcard characters without adding a surrounding pattern.
	 */
	public function escLike(string $value): string;

	/**
	 * Return the charset and collation clause configured by WordPress.
	 */
	public function charsetCollate(): string;
}
