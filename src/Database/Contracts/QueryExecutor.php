<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Exceptions\QueryException;

/**
 * Executes raw write or schema SQL in addition to database reads.
 */
interface QueryExecutor extends QueryReader
{
	/** @throws QueryException When preparation or execution fails. */
	public function execute(string $sql, mixed ...$bindings): int;
}
