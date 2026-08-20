<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Repository;
use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\MigrationFailed;
use StellarWP\Foundation\Database\Exceptions\MigrationLockFailed;
use StellarWP\Foundation\Database\Migration\Exceptions\UnavailableMigration;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use Throwable;

/**
 * Applies and rolls back the configured database migrations while holding a lock.
 */
final readonly class Migrator
{
	/**
	 * Create a migrator for the configured migrations, storage, and lock policy.
	 *
	 * @throws InvalidArgumentException When the migration lock configuration is invalid.
	 */
	public function __construct(
		private Collection $migrations,
		private Repository $repository,
		private Schema $schema,
		private Lock $lock,
		private Store $store,
		private string $lockName = 'foundation-database-migrations',
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
	 * Ensure the migration subsystem storage is ready.
	 *
	 * @throws DatabaseException        When migration storage cannot be prepared.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 */
	public function prepare(): void {
		$this->withLock(function (): void {
			$this->store->prepareLedger($this->schema);
		});
	}

	/**
	 * Drop the migration ledger while preserving shared lock storage.
	 *
	 * @throws DatabaseException        When migration storage cannot be dropped.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 */
	public function dropStore(): void {
		$this->withLock(function (): void {
			$this->store->drop($this->schema);
		});
	}

	/**
	 * Determine whether the complete migration store is ready.
	 *
	 * @throws DatabaseException When migration storage cannot be inspected.
	 */
	public function exists(): bool {
		return $this->store->exists($this->schema);
	}

	/**
	 * Determine whether recorded migration state can be read.
	 *
	 * @throws DatabaseException When the ledger cannot be inspected.
	 */
	public function hasLedger(): bool {
		return $this->store->hasLedger($this->schema);
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
		$configured = $this->migrations->all();

		return $this->withPreparedStore(
			fn (): Result => $this->runPending($configured)
		);
	}

	/**
	 * Roll back the latest configured migration batch.
	 *
	 * @param int|null $batch A migration ledger batch number, available as Status::$batch from status(). Pass null to roll back the latest recorded batch.
	 *
	 * @throws DatabaseException        When migration storage or schema access fails.
	 * @throws MigrationFailed          When a migration fails while rolling back.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 * @throws UnavailableMigration     When a recorded migration implementation is unavailable.
	 */
	public function rollback(?int $batch = null): Result {
		$configured = $this->migrations->all();

		return $this->withPreparedStore(function () use ($configured, $batch): Result {
			$batch ??= $this->repository->latestBatch();

			if ($batch === null) {
				return new Result();
			}

			return $this->rollbackRecords(
				$configured,
				$this->repository->recordsForBatch($batch)
			);
		});
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
		$configured = $this->migrations->all();

		return $this->withPreparedStore(function () use ($configured): Result {
			$rollback = $this->rollbackRecords($configured, array_values($this->repository->all()));
			$run      = $this->runPending($configured);

			return new Result(
				ran: $run->ran,
				rolledBack: $rollback->rolledBack,
				skipped: $run->skipped
			);
		});
	}

	/**
	 * Return the status of every configured and recorded migration.
	 *
	 * @throws DatabaseException When migration storage cannot be inspected.
	 *
	 * @return list<Status>
	 */
	public function status(): array {
		$configured = $this->migrations->all();

		if (! $this->store->hasLedger($this->schema)) {
			return array_map(
				static fn (Migration $migration): Status => Status::pending($migration->id()),
				array_values($configured)
			);
		}

		$records  = $this->repository->all();
		$statuses = [];

		foreach ($configured as $migration) {
			$statuses[] = isset($records[$migration->id()])
				? Status::fromRecord($records[$migration->id()])
				: Status::pending($migration->id());

			unset($records[$migration->id()]);
		}

		foreach ($records as $record) {
			$statuses[] = Status::unavailable($record);
		}

		return $statuses;
	}

	/**
	 * Roll back recorded migrations in reverse order after confirming every implementation is available.
	 *
	 * @param array<string, Migration> $migrations
	 * @param list<Record>             $records
	 *
	 * @throws UnavailableMigration When a recorded migration implementation is unavailable.
	 */
	private function rollbackRecords(array $migrations, array $records): Result {
		usort($records, static fn (Record $a, Record $b): int => $b->id <=> $a->id);
		$unavailable = array_values(array_map(
			static fn (Record $record): string => $record->migration,
			array_filter($records, static fn (Record $record): bool => ! isset($migrations[$record->migration]))
		));

		if ($unavailable !== []) {
			throw new UnavailableMigration($unavailable);
		}

		$rolledBack = [];

		foreach ($records as $record) {
			$migration = $migrations[$record->migration];

			try {
				$migration->down($this->schema);
			} catch (Throwable $throwable) {
				throw MigrationFailed::whileRollingBack($migration->id(), $throwable);
			}

			$this->repository->deleteRun($migration->id());
			$rolledBack[] = $migration->id();
		}

		return new Result(rolledBack: $rolledBack);
	}

	/**
	 * Run migrations that are absent from the ledger and record them in the next batch.
	 *
	 * @param array<string, Migration> $migrations
	 */
	private function runPending(array $migrations): Result {
		$ran     = [];
		$skipped = [];
		$batch   = $this->repository->nextBatch();

		foreach ($migrations as $migration) {
			if ($this->repository->hasRun($migration->id())) {
				$skipped[] = $migration->id();
				continue;
			}

			try {
				$migration->up($this->schema);
			} catch (Throwable $throwable) {
				throw MigrationFailed::whileRunning($migration->id(), $throwable);
			}

			$this->repository->recordRun($migration->id(), $batch);
			$ran[] = $migration->id();
		}

		return new Result(ran: $ran, skipped: $skipped);
	}

	/**
	 * Prepare the migration ledger under the migration lock, then run an operation.
	 *
	 * @template T
	 *
	 * @param callable(): T $operation
	 *
	 * @throws DatabaseException        When migration storage cannot be prepared.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 *
	 * @return T
	 */
	private function withPreparedStore(callable $operation): mixed {
		return $this->withLock(function () use ($operation): mixed {
			$this->store->prepareLedger($this->schema);

			return $operation();
		});
	}

	/**
	 * Run an operation while owning the configured migration lock and release it afterward.
	 *
	 * @template T
	 *
	 * @param callable(): T $operation
	 *
	 * @throws DatabaseException        When lock storage cannot be prepared.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 *
	 * @return T
	 */
	private function withLock(callable $operation): mixed {
		$this->store->prepareLock($this->schema);
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
