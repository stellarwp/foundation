<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Executes a schema definition against the configured database platform.
 */
interface SchemaExecutor
{
	/**
	 * @throws DatabaseException When the schema definition cannot be applied completely.
	 */
	public function execute(string $sql): void;
}
