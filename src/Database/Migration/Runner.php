<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Repository;
use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Exceptions\DuplicateMigration;
use StellarWP\Foundation\Database\Exceptions\MigrationFailed;
use StellarWP\Foundation\Database\Exceptions\MigrationLockFailed;
use StellarWP\Foundation\Lock\Contracts\Lock;
use Throwable;

/**
 * Applies and rolls back migrations while holding a lock.
 */
final readonly class Runner
{
	public function __construct(
		private Repository $repository,
		private Schema $schema,
		private Lock $lock,
		private string $lockName = 'foundation-database-migrations',
		private int $lockTtl = 300
	) {
	}

	/**
	 * @param iterable<Migration> $migrations
	 */
	public function run(iterable $migrations): Result {
		return $this->locked(function () use ($migrations): Result {
			$ran        = [];
			$skipped    = [];
			$batch      = $this->repository->nextBatch();
			$migrations = $this->normalize($migrations);

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
		});
	}

	/**
	 * @param iterable<Migration> $migrations
	 */
	public function rollback(iterable $migrations, ?int $batch = null): Result {
		return $this->locked(function () use ($migrations, $batch): Result {
			$batch ??= $this->repository->latestBatch();

			if ($batch === null) {
				return new Result();
			}

			return $this->rollbackRecords(
				$this->normalize($migrations),
				$this->repository->recordsForBatch($batch)
			);
		});
	}

	/**
	 * @param iterable<Migration> $migrations
	 */
	public function refresh(iterable $migrations): Result {
		return $this->locked(function () use ($migrations): Result {
			$normalized = $this->normalize($migrations);
			$rollback   = $this->rollbackRecords($normalized, array_values($this->repository->all()));
			$run        = $this->runWithoutLock($normalized);

			return new Result(
				ran: $run->ran,
				rolledBack: $rollback->rolledBack,
				skipped: $run->skipped
			);
		});
	}

	/**
	 * @param iterable<Migration> $migrations
	 *
	 * @return list<Status>
	 */
	public function status(iterable $migrations): array {
		$records  = $this->repository->all();
		$statuses = [];

		foreach ($this->normalize($migrations) as $migration) {
			$statuses[] = isset($records[$migration->id()])
				? Status::fromRecord($records[$migration->id()])
				: Status::pending($migration->id());
		}

		return $statuses;
	}

	/**
	 * @param array<string, Migration> $migrations
	 * @param list<Record>             $records
	 */
	private function rollbackRecords(array $migrations, array $records): Result {
		usort($records, static fn (Record $a, Record $b): int => $b->id <=> $a->id);

		$rolledBack = [];
		$skipped    = [];

		foreach ($records as $record) {
			$migration = $migrations[$record->migration] ?? null;

			if ($migration === null) {
				$skipped[] = $record->migration;
				continue;
			}

			try {
				$migration->down($this->schema);
			} catch (Throwable $throwable) {
				throw MigrationFailed::whileRollingBack($migration->id(), $throwable);
			}

			$this->repository->deleteRun($migration->id());
			$rolledBack[] = $migration->id();
		}

		return new Result(rolledBack: $rolledBack, skipped: $skipped);
	}

	/**
	 * @param array<string, Migration> $migrations
	 */
	private function runWithoutLock(array $migrations): Result {
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
	 * @param iterable<Migration> $migrations
	 *
	 * @return array<string, Migration>
	 */
	private function normalize(iterable $migrations): array {
		$normalized = [];

		foreach ($migrations as $migration) {
			if (isset($normalized[$migration->id()])) {
				throw DuplicateMigration::forMigration($migration->id());
			}

			$normalized[$migration->id()] = $migration;
		}

		return $normalized;
	}

	/**
	 * @template T
	 *
	 * @param callable(): T $callback
	 *
	 * @return T
	 */
	private function locked(callable $callback): mixed {
		$token = $this->lock->acquire($this->lockName, $this->lockTtl);

		if ($token === null) {
			throw MigrationLockFailed::forLock($this->lockName);
		}

		try {
			return $callback();
		} finally {
			$this->lock->release($token);
		}
	}
}
