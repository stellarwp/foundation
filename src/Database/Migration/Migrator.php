<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\MigrationFailed;
use StellarWP\Foundation\Database\Exceptions\MigrationLockFailed;
use StellarWP\Foundation\Database\Migration\Exceptions\UnavailableMigration;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;

/**
 * Configured entry point for preparing and running database migrations.
 */
final readonly class Migrator
{
	public function __construct(
		private Runner $runner,
		private Collection $migrations
	) {
	}

	/**
	 * Ensure the migration subsystem storage is ready.
	 *
	 * @throws DatabaseException        When migration storage cannot be prepared.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 */
	public function prepare(): void {
		$this->runner->prepareStore();
	}

	/**
	 * Drop the migration ledger while preserving shared lock storage.
	 *
	 * @throws DatabaseException        When migration storage cannot be prepared or dropped.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 */
	public function dropStore(): void {
		$this->runner->dropStore();
	}

	/**
	 * Determine whether the migration subsystem storage is ready.
	 *
	 * @throws DatabaseException When migration storage cannot be inspected.
	 */
	public function exists(): bool {
		return $this->runner->storeExists();
	}

	/**
	 * Determine whether recorded migration state can be read.
	 *
	 * @throws DatabaseException When the ledger cannot be inspected.
	 */
	public function hasLedger(): bool {
		return $this->runner->hasLedger();
	}

	/**
	 * Run all pending configured migrations.
	 *
	 * @throws DatabaseException        When migration storage or schema access fails.
	 * @throws MigrationFailed          When a migration fails while running.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 */
	public function run(): Result {
		return $this->runner->run($this->migrations);
	}

	/**
	 * Roll back the latest configured migration batch.
	 *
	 * @throws DatabaseException        When migration storage or schema access fails.
	 * @throws MigrationFailed          When a migration fails while rolling back.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 * @throws UnavailableMigration     When a recorded migration implementation is unavailable.
	 */
	public function rollback(?int $batch = null): Result {
		return $this->runner->rollback($this->migrations, $batch);
	}

	/**
	 * Roll back and rerun all configured migrations.
	 *
	 * @throws DatabaseException        When migration storage or schema access fails.
	 * @throws MigrationFailed          When a migration fails while running or rolling back.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 * @throws UnavailableMigration     When a recorded migration implementation is unavailable.
	 */
	public function refresh(): Result {
		return $this->runner->refresh($this->migrations);
	}

	/**
	 * @throws DatabaseException When migration storage cannot be inspected.
	 *
	 * @return list<Status>
	 */
	public function status(): array {
		return $this->runner->status($this->migrations);
	}
}
