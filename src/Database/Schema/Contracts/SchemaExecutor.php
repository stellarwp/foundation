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
	 * Apply a complete schema definition to the configured database.
	 *
	 * @throws DatabaseException When the schema definition cannot be applied completely.
	 */
	public function execute(string $sql): void;
}
