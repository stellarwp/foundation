<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Exceptions\DatabaseContextChanged;
use StellarWP\Foundation\Database\Exceptions\MigrationFailed;
use StellarWP\Foundation\Database\Exceptions\MigrationLockFailed;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use Throwable;

/**
 * Executes migration schema changes while maintaining the active migration lease.
 *
 * @internal Migration sessions are created and owned by the migration store.
 */
final readonly class Session
{
	/**
	 * Create a schema execution session protected by an active migration lease.
	 */
	public function __construct(
		private Schema $schema,
		private Lease $lease
	) {
	}

	/**
	 * Renew the migration lease immediately before and after applying a migration.
	 *
	 * @throws DatabaseContextChanged   When the active database context changes.
	 * @throws MigrationFailed          When the migration fails while running.
	 * @throws MigrationLockFailed      When migration lock ownership is lost.
	 * @throws LockUnavailableException When the lock backend cannot determine the refresh result.
	 */
	public function apply(Migration $migration): void {
		$this->lease->renew();

		try {
			$migration->up($this->schema);
		} catch (Throwable $throwable) {
			throw MigrationFailed::whileRunning($migration->id(), $throwable);
		}

		$this->lease->renew();
	}

	/**
	 * Renew the migration lease immediately before and after reverting a migration.
	 *
	 * @throws DatabaseContextChanged   When the active database context changes.
	 * @throws MigrationFailed          When the migration fails while rolling back.
	 * @throws MigrationLockFailed      When migration lock ownership is lost.
	 * @throws LockUnavailableException When the lock backend cannot determine the refresh result.
	 */
	public function revert(Migration $migration): void {
		$this->lease->renew();

		try {
			$migration->down($this->schema);
		} catch (Throwable $throwable) {
			throw MigrationFailed::whileRollingBack($migration->id(), $throwable);
		}

		$this->lease->renew();
	}
}
