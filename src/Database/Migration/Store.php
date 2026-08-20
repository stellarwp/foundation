<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\MigrationLockFailed;
use StellarWP\Foundation\Database\Migration\Exceptions\UninitializedStore;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use Throwable;

/**
 * Provides safe access to the migration subsystem's initialized database storage.
 *
 * @internal Use Migrator as the supported migration lifecycle entry point.
 */
final readonly class Store
{
	/**
	 * Create the migration store with its schema and lock policy.
	 *
	 * @throws InvalidArgumentException When the migration lock configuration is invalid.
	 */
	public function __construct(
		private Schema $schema,
		private Lock $lock,
		private MigrationTable $migrationTable,
		private LockTable $lockTable,
		private string $lockName = 'nx-foundation-database-migrations',
		private int $lockTtl = 300
	) {
		if (trim($this->lockName) === '') {
			throw new InvalidArgumentException('The migration lock name cannot be empty.');
		}

		if ($this->lockTtl < 1) {
			throw new InvalidArgumentException('The migration lock TTL must be at least one second.');
		}
	}

	/**
	 * Initialize or reconcile the complete migration store before migrations run.
	 *
	 * @throws DatabaseException        When migration storage cannot be initialized.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 */
	public function initialize(): void {
		$this->schema->createOrUpdate($this->lockTable);

		$this->withLock(function (): void {
			$this->schema->createOrUpdate($this->migrationTable);
		});
	}

	/**
	 * Drop the migration ledger while preserving shared lock storage.
	 *
	 * @throws DatabaseException        When the ledger cannot be dropped.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 * @throws UninitializedStore       When migration storage has not been initialized.
	 */
	public function drop(): void {
		$this->withMigrationLock(function (Schema $schema): void {
			$schema->drop($this->migrationTable);
		});
	}

	/**
	 * Determine whether the migration subsystem storage is ready.
	 *
	 * @throws DatabaseException When migration storage cannot be inspected.
	 */
	public function isInitialized(): bool {
		return $this->hasLedger() && $this->schema->hasTable($this->lockTable);
	}

	/**
	 * Determine whether recorded migration state can be read.
	 *
	 * @throws DatabaseException When migration storage cannot be inspected.
	 */
	public function hasLedger(): bool {
		return $this->schema->hasTable($this->migrationTable);
	}

	/**
	 * Run an operation against initialized migration storage while holding its lock.
	 *
	 * @template T
	 *
	 * @param callable(Schema): T $operation The operation that receives the schema while the migration lock is held.
	 *
	 * @throws DatabaseException        When migration storage cannot be inspected.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 * @throws UninitializedStore       When migration storage has not been initialized.
	 *
	 * @return T
	 */
	public function withMigrationLock(callable $operation): mixed {
		$this->assertInitialized();

		return $this->withLock(fn (): mixed => $operation($this->schema));
	}

	/**
	 * Reject migration operations until the internal store has been initialized.
	 *
	 * @throws DatabaseException  When migration storage cannot be inspected.
	 * @throws UninitializedStore When migration storage has not been initialized.
	 */
	private function assertInitialized(): void {
		if (! $this->isInitialized()) {
			throw new UninitializedStore();
		}
	}

	/**
	 * Run an operation while owning the configured migration lock and release it afterward.
	 *
	 * The lock table is the one bootstrap exception: it must exist before its own
	 * database-backed lock can be acquired.
	 *
	 * @template T
	 *
	 * @param callable(): T $operation
	 *
	 * @throws DatabaseException        When migration storage access fails.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 *
	 * @return T
	 */
	private function withLock(callable $operation): mixed {
		$token = $this->lock->acquire($this->lockName, $this->lockTtl);

		if ($token === null) {
			throw MigrationLockFailed::forLock($this->lockName);
		}

		try {
			$result = $operation();
		} catch (Throwable $failure) {
			try {
				$this->lock->release($token);
			} catch (Throwable) {
				// Preserve the primary migration failure when cleanup also fails.
			}

			throw $failure;
		}

		if (! $this->lock->release($token)) {
			throw MigrationLockFailed::forUnconfirmedOwnership($this->lockName);
		}

		return $result;
	}
}
