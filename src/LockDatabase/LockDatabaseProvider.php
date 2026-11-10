<?php declare(strict_types=1);

namespace StellarWP\Foundation\LockDatabase;

use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Traits\ResolvesFoundationPrefix;
use StellarWP\Foundation\LockDatabase\Tables\LockTable;

/**
 * Supply a database lock using the application's shared Foundation database connection.
 */
final class LockDatabaseProvider extends Provider
{
	use ResolvesFoundationPrefix;

	/**
	 * Register the lock and its storage configuration for explicit application selection.
	 *
	 * @throws \InvalidArgumentException When the Foundation resource prefix is invalid.
	 */
	public function register(): void {
		$prefix = str_replace('-', '_', $this->foundationPrefix());
		$this->container->when(LockTable::class)
			->needs('$unprefixedTableName')
			->give($this->config->get('lock.database.table', $prefix . '_foundation_locks'));

		$this->container->singleton(LockTable::class);
		$this->container->singleton(DatabaseLock::class);
	}
}
