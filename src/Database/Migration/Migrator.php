<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\DuplicateMigration;
use StellarWP\Foundation\Database\Exceptions\MigrationFailed;
use StellarWP\Foundation\Database\Exceptions\MigrationLockFailed;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;

/**
 * Configured entry point for preparing and running database migrations.
 */
final readonly class Migrator
{
	public function __construct(
		private Store $store,
		private Runner $runner,
		private Collection $migrations
	) {
	}

	/**
	 * Ensure the migration subsystem storage is ready.
	 *
	 * @throws DatabaseException When migration storage cannot be prepared.
	 */
	public function prepare(): void {
		$this->store->prepare();
	}

	/**
	 * Drop the migration subsystem storage.
	 *
	 * @throws DatabaseException When migration storage cannot be dropped.
	 */
	public function drop(): void {
		$this->store->drop();
	}

	/**
	 * Determine whether the migration subsystem storage is ready.
	 *
	 * @throws DatabaseException When migration storage cannot be inspected.
	 */
	public function exists(): bool {
		return $this->store->exists();
	}

	/**
	 * Run all pending configured migrations.
	 *
	 * @throws DatabaseException        When migration storage or schema access fails.
	 * @throws DuplicateMigration       When configured migrations share an identifier.
	 * @throws MigrationFailed          When a migration fails while running.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 */
	public function run(): Result {
		return $this->withPreparedStore(fn (): Result => $this->runner->run($this->migrations));
	}

	/**
	 * Roll back the latest configured migration batch.
	 *
	 * @throws DatabaseException        When migration storage or schema access fails.
	 * @throws DuplicateMigration       When configured migrations share an identifier.
	 * @throws MigrationFailed          When a migration fails while rolling back.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 */
	public function rollback(?int $batch = null): Result {
		return $this->withPreparedStore(fn (): Result => $this->runner->rollback($this->migrations, $batch));
	}

	/**
	 * Roll back and rerun all configured migrations.
	 *
	 * @throws DatabaseException        When migration storage or schema access fails.
	 * @throws DuplicateMigration       When configured migrations share an identifier.
	 * @throws MigrationFailed          When a migration fails while running or rolling back.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 */
	public function refresh(): Result {
		return $this->withPreparedStore(fn (): Result => $this->runner->refresh($this->migrations));
	}

	/**
	 * @throws DatabaseException  When migration storage cannot be inspected.
	 * @throws DuplicateMigration When configured migrations share an identifier.
	 *
	 * @return list<Status>
	 */
	public function status(): array {
		if (! $this->store->exists()) {
			return array_map(
				static fn (Migration $migration): Status => Status::pending($migration->id()),
				$this->migrations->all()
			);
		}

		return $this->runner->status($this->migrations);
	}

	/**
	 * @template T
	 *
	 * @param callable(): T $callback
	 *
	 * @return T
	 */
	private function withPreparedStore(callable $callback): mixed {
		$this->store->prepare();

		return $callback();
	}
}
