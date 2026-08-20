<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Repository;
use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\MigrationFailed;
use StellarWP\Foundation\Database\Exceptions\MigrationLockFailed;
use StellarWP\Foundation\Database\Migration\Exceptions\UnavailableMigration;
use StellarWP\Foundation\Database\Migration\Exceptions\UninitializedStore;
use StellarWP\Foundation\Database\Migration\ValueObjects\Record;
use StellarWP\Foundation\Database\Migration\ValueObjects\Result;
use StellarWP\Foundation\Database\Migration\ValueObjects\Status;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use Throwable;

/**
 * Applies and rolls back configured database migrations through an initialized store.
 */
final readonly class Migrator
{
	public function __construct(
		private Collection $migrations,
		private Repository $repository,
		private Store $store
	) {
	}

	/**
	 * Initialize or reconcile migration storage before other migration operations run.
	 *
	 * @throws DatabaseException        When migration storage cannot be initialized.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 */
	public function initialize(): void {
		$this->store->initialize();
	}

	/**
	 * Drop the migration ledger while preserving shared lock storage.
	 *
	 * @throws DatabaseException        When migration storage cannot be dropped.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 * @throws UninitializedStore       When migration storage has not been initialized.
	 */
	public function dropStore(): void {
		$this->store->drop();
	}

	/**
	 * Determine whether the complete migration store has been initialized.
	 *
	 * @throws DatabaseException When migration storage cannot be inspected.
	 */
	public function isInitialized(): bool {
		return $this->store->isInitialized();
	}

	/**
	 * Run all pending configured migrations.
	 *
	 * @throws DatabaseException        When migration storage or schema access fails.
	 * @throws MigrationFailed          When a migration fails while running.
	 * @throws MigrationLockFailed      When the lock cannot be acquired or ownership cannot be confirmed during release.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 * @throws UninitializedStore       When migration storage has not been initialized.
	 */
	public function run(): Result {
		$configured = $this->migrations->all();

		return $this->store->withMigrationLock(
			fn (Schema $schema): Result => $this->runPending($configured, $schema)
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
	 * @throws UninitializedStore       When migration storage has not been initialized.
	 */
	public function rollback(?int $batch = null): Result {
		$configured = $this->migrations->all();

		return $this->store->withMigrationLock(function (Schema $schema) use ($configured, $batch): Result {
			$batch ??= $this->repository->latestBatch();

			if ($batch === null) {
				return new Result();
			}

			return $this->rollbackRecords(
				$configured,
				$this->repository->recordsForBatch($batch),
				$schema
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
	 * @throws UninitializedStore       When migration storage has not been initialized.
	 */
	public function refresh(): Result {
		$configured = $this->migrations->all();

		return $this->store->withMigrationLock(function (Schema $schema) use ($configured): Result {
			$rollback = $this->rollbackRecords($configured, array_values($this->repository->all()), $schema);
			$run      = $this->runPending($configured, $schema);

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

		if (! $this->store->hasLedger()) {
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
	 * @param Schema                   $schema     The initialized schema supplied by the migration store.
	 *
	 * @throws UnavailableMigration When a recorded migration implementation is unavailable.
	 */
	private function rollbackRecords(array $migrations, array $records, Schema $schema): Result {
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
				$migration->down($schema);
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
	 * @param Schema                   $schema     The initialized schema supplied by the migration store.
	 */
	private function runPending(array $migrations, Schema $schema): Result {
		$ran     = [];
		$skipped = [];
		$batch   = $this->repository->nextBatch();

		foreach ($migrations as $migration) {
			if ($this->repository->hasRun($migration->id())) {
				$skipped[] = $migration->id();
				continue;
			}

			try {
				$migration->up($schema);
			} catch (Throwable $throwable) {
				throw MigrationFailed::whileRunning($migration->id(), $throwable);
			}

			$this->repository->recordRun($migration->id(), $batch);
			$ran[] = $migration->id();
		}

		return new Result(ran: $ran, skipped: $skipped);
	}
}
