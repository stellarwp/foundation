<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\DatabaseScope;
use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\MigrationLockFailed;
use StellarWP\Foundation\Database\Migration\Exceptions\UninitializedStore;
use StellarWP\Foundation\Database\Migration\Factories\LeaseFactory;
use StellarWP\Foundation\Database\Migration\Factories\SessionFactory;
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
		private LeaseFactory $leaseFactory,
		private SessionFactory $sessionFactory,
		private DatabaseScope $scope,
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
		$scopeId = $this->scope->capture();

		$this->schema->createOrUpdate($this->lockTable);
		$lease = $this->acquireLease($scopeId);

		try {
			$this->schema->createOrUpdate($this->migrationTable);
		} catch (Throwable $failure) {
			$this->releasePreservingFailure($lease, $failure);
		}

		$lease->release();
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
		$this->withMigrationLock(function (): void {
			$this->schema->drop($this->migrationTable);
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
	 * @param callable(Session): T $operation The operation receives a session that maintains the migration lease around each schema change.
	 *
	 * @throws DatabaseException        When migration storage cannot be inspected.
	 * @throws MigrationLockFailed      When the lock cannot be acquired, renewed, or released.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 * @throws UninitializedStore       When migration storage has not been initialized.
	 *
	 * @return T
	 */
	public function withMigrationLock(callable $operation): mixed {
		$scopeId = $this->scope->capture();

		// Migration lock storage must exist before lock acquisition.
		$hasLockTable = $this->schema->hasTable($this->lockTable);
		$this->scope->assertCurrent($scopeId);

		if (! $hasLockTable) {
			throw new UninitializedStore();
		}

		$lease = $this->acquireLease($scopeId);

		try {
			// The ledger may have changed before this process acquired the lock.
			$this->assertInitialized();
			$this->scope->assertCurrent($scopeId);

			$result = $operation($this->sessionFactory->create($this->schema, $lease));
		} catch (Throwable $failure) {
			$this->releasePreservingFailure($lease, $failure);
		}

		$lease->release();

		return $result;
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
	 * Acquire a migration lease in the captured database scope.
	 *
	 * The lock table is the one bootstrap exception: it must exist before its own
	 * database-backed lock can be acquired.
	 *
	 * @throws DatabaseException        When database scope validation fails.
	 * @throws MigrationLockFailed      When the lock cannot be acquired.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 */
	private function acquireLease(int $scopeId): Lease {
		$this->scope->assertCurrent($scopeId);
		$token = $this->lock->acquire($this->lockName, $this->lockTtl);
		$this->scope->assertCurrent($scopeId);

		if ($token === null) {
			throw MigrationLockFailed::forLock($this->lockName);
		}

		return $this->leaseFactory->create(
			$this->lock,
			$this->scope,
			$scopeId,
			$token,
			$this->lockTtl
		);
	}

	/**
	 * Attempt to release a migration lease without replacing the primary failure.
	 *
	 * @throws Throwable Always rethrows the primary operation failure.
	 */
	private function releasePreservingFailure(Lease $lease, Throwable $failure): never {
		try {
			$lease->release();
		} catch (Throwable) {
			// Preserve the primary failure and let the lock expire when cleanup is uncertain.
		}

		throw $failure;
	}
}
