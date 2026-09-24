<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations;

use Closure;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\AdvisorySession;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;
use StellarWP\Foundation\Database\Exceptions\AdvisoryLockContended;
use StellarWP\Foundation\Database\Exceptions\AdvisoryLockInterrupted;
use StellarWP\Foundation\Migrations\Contracts\DescribesMigration;
use StellarWP\Foundation\Migrations\Contracts\MigratesData;
use StellarWP\Foundation\Migrations\Contracts\Migration;
use StellarWP\Foundation\Migrations\Exceptions\MigrationAlreadyRunning;
use StellarWP\Foundation\Migrations\Exceptions\MigrationInterrupted;
use StellarWP\Foundation\Migrations\Schema\SchemaPlanner;
use StellarWP\Foundation\Migrations\ValueObjects\MigrationStatus;
use StellarWP\Foundation\Migrations\ValueObjects\Step;
use Throwable;

/**
 * Apply or reverse declarative migrations by diffing desired states against the live schema.
 *
 * For each step the runner replays history in memory to obtain the desired schema before and
 * after the migration, introspects the owned tables, and executes only the remaining difference.
 * Work a previous attempt already completed produces no SQL; differences the migration never
 * declared stop the run. All of this happens under one MySQL advisory lock on the shared session.
 */
final class Migrator
{
	public const string LATEST = 'latest';
	public const string NONE   = '0';

	/**
	 * Receive the shared session, history, and planning services.
	 *
	 * @internal Constructed by Foundation; applications receive this object through provider wiring or migration callbacks.
	 */
	public function __construct(
		private readonly Connection $db,
		private readonly AdvisorySession $session,
		private readonly History $history,
		private readonly SchemaPlanner $planner,
		private readonly MigrationCollection $migrations,
		private readonly TableNameResolver $names,
	) {
	}

	/**
	 * Apply pending migrations up to the target, or reverse applied migrations above it.
	 *
	 * @throws Throwable When planning, DDL, a data step, the ledger, or lock ownership fails.
	 *
	 * @return list<Step>
	 */
	public function migrate(string $target = self::LATEST): array {
		$this->assertTarget($target);

		return $this->locked(fn (): array => $this->run($target, true));
	}

	/**
	 * Reverse the highest applied migration IDs, one by default.
	 *
	 * The target is selected from history read under the same lock that protects execution, so a
	 * migration another process applies in the meantime cannot widen the reversal.
	 *
	 * @return list<Step>
	 */
	public function rollback(int $steps = 1): array {
		if ($steps < 1) {
			throw new InvalidArgumentException('Rollback steps must be positive.');
		}

		return $this->locked(function () use ($steps): array {
			$applied = array_map('strval', array_keys($this->history->applied()));

			if ($applied === []) {
				return [];
			}
			$remaining = array_slice($applied, 0, max(0, count($applied) - $steps));

			return $this->run($remaining === [] ? self::NONE : (string) end($remaining), true, false);
		});
	}

	/**
	 * Reverse applied migrations above the target without applying pending migrations.
	 *
	 * @throws Throwable When the target is unknown, an inverse fails, or ownership is lost.
	 *
	 * @return list<Step>
	 */
	public function rollbackTo(string $target): array {
		$this->assertTarget($target);

		return $this->locked(fn (): array => $this->run($target, true, false));
	}

	/**
	 * Reverse all migrations and reapply them under one uninterrupted migration lock.
	 *
	 * @throws Throwable When an inverse, execution, or ownership check fails.
	 *
	 * @return list<Step>
	 */
	public function refresh(): array {
		return $this->locked(fn (): array => array_merge($this->run(self::NONE, true), $this->run(self::LATEST, true)));
	}

	/**
	 * One advisory lock per site ledger: independent plugins and sites never block each other.
	 *
	 * @template T
	 *
	 * @param Closure(): T $operation
	 *
	 * @return T
	 */
	private function locked(Closure $operation): mixed {
		try {
			return $this->session->withAdvisoryLock($this->history->table(), function () use ($operation): mixed {
				try {
					return $operation();
				} catch (Throwable $failure) {
					try {
						while ($this->db->isTransactionActive()) {
							$this->db->rollBack();
						}
					} catch (Throwable) {
						// Preserve the migration failure when transaction cleanup also fails.
					}

					throw $failure;
				}
			});
		} catch (AdvisoryLockContended $failure) {
			throw new MigrationAlreadyRunning('Another session is running these migrations; retry after it completes.', 0, $failure);
		} catch (AdvisoryLockInterrupted $failure) {
			throw new MigrationInterrupted('Migration interrupted: ' . $failure->getMessage(), 0, $failure);
		}
	}

	/**
	 * Compute the SQL each step would execute, without executing or recording anything.
	 *
	 * @return list<Step>
	 */
	public function preview(string $target = self::LATEST): array {
		$this->assertTarget($target);

		return $this->run($target, false);
	}

	/**
	 * List registered and recorded migrations without mutating storage.
	 *
	 * @return list<MigrationStatus>
	 */
	public function status(): array {
		$applied = $this->history->applied();
		$rows    = [];

		foreach ($this->migrations->all() as $id => $migration) {
			$id        = (string) $id;
			$rows[$id] = new MigrationStatus(
				$id,
				self::displayName($migration, $id),
				$migration instanceof DescribesMigration ? $migration->describe() : '',
				$applied[$id] ?? null,
			);
		}

		foreach ($applied as $id => $appliedAt) {
			$rows[$id] ??= new MigrationStatus((string) $id, null, '', $appliedAt);
		}
		ksort($rows, SORT_STRING);

		return array_values($rows);
	}

	/**
	 * @return list<Step>
	 */
	private function run(string $target, bool $execute, bool $applyPending = true): array {
		if ($execute) {
			$this->history->initialize();
		}
		$applied        = array_map('strval', array_keys($this->history->applied()));
		$steps          = [];
		$simulated      = null;
		$simulatedNames = [];

		foreach ($this->plan($target, $applied, $applyPending) as [$id, $reverse]) {
			$migration = $this->migrations->get($id);
			$afterIds  = $reverse ? array_values(array_diff($applied, [$id])) : array_merge($applied, [$id]);
			$change    = $this->planner->plan($applied, $id, $reverse, $simulated, $simulatedNames);
			$sql       = $change->sql;

			if ($execute) {
				foreach ($sql as $statement) {
					$this->db->executeStatement($statement);
				}
				$this->session->check();

				if (! $reverse && $migration instanceof MigratesData) {
					$migration->migrate(new DataMigrationContext($this->db, $this->names));
					$this->session->check();

					if ($this->db->isTransactionActive()) {
						throw new MigrationInterrupted('A migration data callback left a transaction open; complete its transaction before returning.');
					}
				}

				try {
					$reverse ? $this->history->remove($id) : $this->history->record($id);
				} catch (Throwable $failure) {
					throw new Exceptions\LedgerFailure('Migration ' . $id . ' changed the schema but could not update its history; retry the migration.', 0, $failure);
				}
			} else {
				$simulated      = $change->schema;
				$simulatedNames = array_values(array_unique(array_merge($simulatedNames, array_map('strtolower', $change->tableNames))));
			}

			$applied = $afterIds;
			$steps[] = new Step($id, self::displayName($migration, $id), $reverse, $sql, ! $reverse && $migration instanceof MigratesData);
		}

		return $steps;
	}

	/**
	 * Select forward or reverse work. An explicit target names the version that must remain applied.
	 *
	 * @param list<string> $applied
	 *
	 * @return list<array{string, bool}>
	 */
	private function plan(string $target, array $applied, bool $applyPending): array {
		$reverse = $target === self::LATEST ? [] : array_values(array_filter(
			$applied,
			static fn (string $id): bool => $target === self::NONE || strcmp($id, $target) > 0,
		));
		rsort($reverse, SORT_STRING);
		$this->assertKnown($applied);
		$plan = array_map(static fn (string $id): array => [$id, true], $reverse);

		if ($target === self::NONE || ! $applyPending) {
			return $plan;
		}
		foreach ($this->migrations->ids() as $id) {
			if (in_array($id, $applied, true) || ($target !== self::LATEST && strcmp($id, $target) > 0)) {
				continue;
			}
			$plan[] = [$id, false];
		}

		return $plan;
	}

	/**
	 * @param list<string> $ids
	 */
	private function assertKnown(array $ids): void {
		foreach ($ids as $id) {
			if (! $this->migrations->has($id)) {
				throw new MigrationInterrupted('Restore the missing migration before running migrations: ' . $id);
			}
		}
	}

	private function assertTarget(string $target): void {
		if (! in_array($target, [self::NONE, self::LATEST], true) && ! $this->migrations->has($target)) {
			throw new InvalidArgumentException('Unknown migration target: ' . $target);
		}
	}

	private static function displayName(Migration $migration, string $id): string {
		if (str_contains($migration::class, "\0")) {
			return $id;
		}
		$parts = explode('\\', $migration::class);

		return (string) end($parts);
	}
}
