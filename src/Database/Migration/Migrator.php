<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Migration\Contracts\Repository;
use StellarWP\Foundation\Database\Migration\Exceptions\InvalidRollbackBatch;
use StellarWP\Foundation\Database\Migration\Exceptions\LedgerFailure;
use StellarWP\Foundation\Database\Migration\Exceptions\MigrationFailed;
use StellarWP\Foundation\Database\Migration\Exceptions\MigrationLockFailed;
use StellarWP\Foundation\Database\Migration\Exceptions\UnavailableMigration;
use StellarWP\Foundation\Database\Migration\Exceptions\UninitializedStore;
use StellarWP\Foundation\Database\Migration\ValueObjects\Record;
use StellarWP\Foundation\Database\Migration\ValueObjects\Result;
use StellarWP\Foundation\Database\Migration\ValueObjects\Status;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;

/**
 * Applies and rolls back configured database migrations through an initialized store.
 */
final readonly class Migrator
{
	/**
	 * Create the migration entry point from configured migrations, ledger, and store services.
	 */
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
	 * @throws LedgerFailure            When an applied migration cannot be recorded in the ledger.
	 * @throws MigrationFailed          When a migration fails while running.
	 * @throws MigrationLockFailed      When the lock cannot be acquired, renewed, or released.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 * @throws UninitializedStore       When migration storage has not been initialized.
	 */
	public function run(): Result {
		$migrations = $this->migrations->all();

		return $this->store->withMigrationLock(
			fn (Session $session): Result => $this->runPending($migrations, $session)
		);
	}

	/**
	 * Roll back the latest recorded migration batch.
	 *
	 * @param int|null $expectedLatestBatch The expected latest batch, available as Status::$batch from status(). Pass null to roll back whichever batch is latest.
	 *
	 * @throws DatabaseException        When migration storage or schema access fails.
	 * @throws InvalidRollbackBatch     When the requested batch does not match the latest recorded batch.
	 * @throws LedgerFailure            When a rolled-back migration ledger record cannot be deleted.
	 * @throws MigrationFailed          When a migration fails while rolling back.
	 * @throws MigrationLockFailed      When the lock cannot be acquired, renewed, or released.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 * @throws UnavailableMigration     When a recorded migration implementation is unavailable.
	 * @throws UninitializedStore       When migration storage has not been initialized.
	 */
	public function rollback(?int $expectedLatestBatch = null): Result {
		return $this->store->withMigrationLock(function (Session $session) use ($expectedLatestBatch): Result {
			$configured  = $this->migrations->all();
			$latestBatch = $this->repository->latestBatch();

			if ($expectedLatestBatch !== null && $expectedLatestBatch !== $latestBatch) {
				throw new InvalidRollbackBatch($expectedLatestBatch, $latestBatch);
			}

			if ($latestBatch === null) {
				return new Result();
			}

			$batch = $latestBatch;

			return $this->rollbackRecords(
				$configured,
				$this->repository->recordsForBatch($batch),
				$session
			);
		});
	}

	/**
	 * Roll back and rerun all configured migrations.
	 *
	 * @throws DatabaseException        When migration storage or schema access fails.
	 * @throws LedgerFailure            When an applied migration cannot be recorded in the ledger.
	 * @throws MigrationFailed          When a migration fails while running or rolling back.
	 * @throws MigrationLockFailed      When the lock cannot be acquired, renewed, or released.
	 * @throws LockUnavailableException When the lock backend cannot determine the lock state.
	 * @throws UnavailableMigration     When a recorded migration implementation is unavailable.
	 * @throws UninitializedStore       When migration storage has not been initialized.
	 */
	public function refresh(): Result {
		return $this->store->withMigrationLock(function (Session $session): Result {
			$configured = $this->migrations->all();
			$rollback   = $this->rollbackRecords($configured, array_values($this->repository->all()), $session);
			$run        = $this->runPending($configured, $session);

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
				? Status::applied($records[$migration->id()])
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
	 * @param list<Record>             $records    Ledger records in ascending execution order.
	 * @param Session                  $session    The active migration session that maintains lock ownership.
	 *
	 * @throws LedgerFailure            When a rolled-back migration ledger record cannot be deleted.
	 * @throws MigrationFailed          When a migration fails while rolling back.
	 * @throws MigrationLockFailed      When the migration lock cannot be renewed.
	 * @throws LockUnavailableException When the lock backend cannot determine the refresh result.
	 * @throws UnavailableMigration     When a recorded migration implementation is unavailable.
	 */
	private function rollbackRecords(array $migrations, array $records, Session $session): Result {
		$records     = array_reverse($records);
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
			$session->revert($migration);

			if (! $this->repository->deleteRun($migration->id())) {
				throw LedgerFailure::notDeletedAfterRollback($migration->id());
			}

			$rolledBack[] = $migration->id();
		}

		return new Result(rolledBack: $rolledBack);
	}

	/**
	 * Run migrations that are absent from the ledger and record them in the next batch.
	 *
	 * @param array<string, Migration> $migrations
	 * @param Session                  $session    The active migration session that maintains lock ownership.
	 *
	 * @throws DatabaseException        When migration ledger access fails.
	 * @throws LedgerFailure            When an applied migration cannot be recorded in the ledger.
	 * @throws MigrationFailed          When a migration fails while running.
	 * @throws MigrationLockFailed      When the migration lock cannot be renewed.
	 * @throws LockUnavailableException When the lock backend cannot determine the refresh result.
	 */
	private function runPending(array $migrations, Session $session): Result {
		$records = $this->repository->all();
		$ran     = [];
		$skipped = [];
		$batch   = ($this->repository->latestBatch() ?? 0) + 1;

		foreach ($migrations as $migration) {
			if (isset($records[$migration->id()])) {
				$skipped[] = $migration->id();
				continue;
			}

			$session->apply($migration);

			$this->repository->recordRun($migration->id(), $batch);
			$ran[] = $migration->id();
		}

		return new Result(ran: $ran, skipped: $skipped);
	}
}
