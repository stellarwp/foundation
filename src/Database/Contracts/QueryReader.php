<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\QueryException;

/**
 * Prepares SQL templates and executes queries that return database values.
 */
interface QueryReader
{
	/**
	 * Prepare a SQL template using WordPress placeholder bindings.
	 *
	 * @throws QueryException When the template is invalid or cannot be prepared.
	 */
	public function prepare(string $sql, mixed ...$bindings): string;

	/**
	 * Execute a query and return its first row when one is available.
	 *
	 * @throws DatabaseException When the database returns a row in an invalid shape.
	 * @throws QueryException    When preparation or execution fails.
	 *
	 * @return array<string, mixed>|null
	 */
	public function row(string $sql, mixed ...$bindings): ?array;

	/**
	 * Execute a query and return every matching row.
	 *
	 * @throws DatabaseException When the database returns a row in an invalid shape.
	 * @throws QueryException    When preparation or execution fails.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function rows(string $sql, mixed ...$bindings): array;

	/**
	 * Execute a query and return the first column from its first row.
	 *
	 * @throws QueryException When preparation or execution fails.
	 */
	public function value(string $sql, mixed ...$bindings): mixed;
}
