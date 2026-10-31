<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Schema\Contracts;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Executes a schema definition against the configured database platform.
 *
 * @internal This is the execution boundary used by Foundation's schema reconciler.
 */
interface SchemaExecutor
{
	/**
	 * Execute a schema definition; the caller verifies the resulting physical state.
	 *
	 * @throws DatabaseException When schema execution is unavailable or reports a database error.
	 */
	public function execute(string $sql): void;
}
