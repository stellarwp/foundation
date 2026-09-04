<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\QueryException;

/**
 * Prepares SQL templates and executes queries that return database values.
 */
interface QueryReader
{
	/** @throws QueryException When the template is invalid or cannot be prepared. */
	public function prepare(string $sql, mixed ...$bindings): string;

	/**
	 * @throws DatabaseException When the database returns a row in an invalid shape.
	 * @throws QueryException    When preparation or execution fails.
	 *
	 * @return array<string, mixed>|null
	 */
	public function row(string $sql, mixed ...$bindings): ?array;

	/**
	 * @throws DatabaseException When the database returns a row in an invalid shape.
	 * @throws QueryException    When preparation or execution fails.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function rows(string $sql, mixed ...$bindings): array;

	/** @throws QueryException When preparation or execution fails. */
	public function value(string $sql, mixed ...$bindings): mixed;
}
