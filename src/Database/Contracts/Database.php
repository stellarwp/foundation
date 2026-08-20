<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\QueryException;
use StellarWP\Foundation\Database\Query\QueryBuilder;

/**
 * Executes WordPress database queries and provides the package's developer-facing database API.
 */
interface Database
{
	public function table(Table|string $table, ?string $alias = null): QueryBuilder;

	public function tableName(Table|string $table): string;

	/**
	 * @throws DatabaseException When table inspection fails.
	 */
	public function tableExists(Table|string $table): bool;

	/**
	 * @throws DatabaseException When column inspection fails.
	 */
	public function columnExists(Table|string $table, string $column): bool;

	/**
	 * @throws DatabaseException When index inspection fails.
	 */
	public function indexExists(Table|string $table, string $index): bool;

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
	 * @throws QueryException When the insert fails.
	 *
	 * @return int Number of inserted rows.
	 */
	public function insert(Table|string $table, array $data): int;

	/**
	 * Insert a row and return its auto-increment identifier.
	 *
	 * @param array<string, mixed> $data
	 *
	 * @throws QueryException When the insert fails.
	 */
	public function insertGetId(Table|string $table, array $data): int;

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 *
	 * @throws QueryException When the update fails.
	 */
	public function update(Table|string $table, array $data, array $where): int;

	/**
	 * @param array<string, mixed> $where
	 *
	 * @throws QueryException When the delete fails.
	 */
	public function delete(Table|string $table, array $where): int;

	public function quoteIdentifier(string $identifier): string;

	public function escLike(string $value): string;

	public function charsetCollate(): string;
}
