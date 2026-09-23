<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Migration\Exceptions\IncompatibleSchema;
use StellarWP\Foundation\Database\Migration\Exceptions\MigrationAlreadyRunning;
use StellarWP\Foundation\Database\Migration\Exceptions\MigrationInterrupted;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Migration\ValueObjects\Step;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\AddEntryNote;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\BackfillEntryStatus;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\CreateEntries;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\EntriesTable;

/**
 * Prove the desired-state runner: guard-free migrations, safe retry, drift detection, preview, rollback.
 */
final class DeclarativeMigratorTest extends DatabaseTestCase
{
	private const string CREATE   = CreateEntries::ID;
	private const string ALTER    = AddEntryNote::ID;
	private const string BACKFILL = BackfillEntryStatus::ID;

	private EntriesTable $entries;
	private string $entriesName;
	private string $quotedEntries;
	private string $historyName;
	private string $quotedHistory;

	protected function configuration(): array {
		return ['database' => ['migrations_table' => $this->suffix . '_history']];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->entries       = new EntriesTable($this->suffix . '_entries');
		$this->entriesName   = $this->source->prefix . $this->suffix . '_entries';
		$this->quotedEntries = $this->privateTable($this->suffix . '_entries');
		$this->historyName   = $this->source->prefix . $this->suffix . '_history';
		$this->quotedHistory = $this->privateTable($this->suffix . '_history');

		// What an application's Database_Provider does: contribute migrations lazily.
		$this->container->singleton(EntriesTable::class, $this->entries);
		$this->container->when(BackfillEntryStatus::class)->needs('$physicalName')->give($this->entriesName);
		$this->container->mergeArrayVar(DatabaseProvider::MIGRATIONS, static fn (C $c): array => [
			$c->get(BackfillEntryStatus::class),
			$c->get(AddEntryNote::class),
			$c->get(CreateEntries::class),
		]);
	}

	private function migrator(): Migrator {
		return $this->container->get(Migrator::class);
	}

	/**
	 * @return list<string>
	 */
	private function history(): array {
		return $this->observer->fetchFirstColumn('SELECT version FROM ' . $this->quotedHistory . ' ORDER BY version');
	}

	/**
	 * @param list<Step> $steps
	 *
	 * @return list<string>
	 */
	private static function ids(array $steps): array {
		return array_map(static fn (Step $step): string => $step->id, $steps);
	}

	private function createTableSql(): string {
		$row = $this->observer->fetchAssociative('SHOW CREATE TABLE ' . $this->quotedEntries);
		self::assertIsArray($row);

		return strtolower((string) $row['Create Table']);
	}

	private function rejectHistoryWrite(string $event): string {
		$trigger = $this->observer->getDatabasePlatform()->quoteSingleIdentifier($this->suffix . '_reject_history');
		$this->observer->executeStatement("CREATE TRIGGER {$trigger} BEFORE {$event} ON {$this->quotedHistory} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'History write failure'");

		return $trigger;
	}

	public function test_missing_history_is_visible_and_prevents_execution(): void {
		$this->migrator()->migrate();
		$this->observer->insert($this->quotedHistory, ['version' => '20270101000100']);
		$statuses = $this->migrator()->status();
		$last     = end($statuses);
		self::assertInstanceOf(\StellarWP\Foundation\Database\Migration\ValueObjects\MigrationStatus::class, $last);
		self::assertSame('missing', $last->state());
		$this->expectException(MigrationInterrupted::class);
		$this->migrator()->migrate();
	}

	public function test_rollback_on_an_empty_history_has_no_work(): void {
		self::assertSame([], $this->migrator()->rollback());
		self::assertFalse($this->observer->createSchemaManager()->tablesExist([$this->historyName]));
	}

	public function test_nonpositive_rollback_steps_are_rejected_without_initializing_history(): void {
		try {
			$this->migrator()->rollback(0);
			self::fail('Invalid rollback count must fail.');
		} catch (\InvalidArgumentException) {
			self::assertFalse($this->observer->createSchemaManager()->tablesExist([$this->historyName]));
		}
	}

	public function test_unknown_target_is_rejected_before_creating_history(): void {
		try {
			$this->migrator()->migrate('unknown');
			self::fail('Unknown target must fail.');
		} catch (\InvalidArgumentException) {
			self::assertFalse($this->observer->createSchemaManager()->tablesExist([$this->historyName]));
		}
	}

	public function test_step_rollback_does_not_apply_an_older_pending_migration(): void {
		$this->migrator()->migrate();
		$this->observer->delete($this->quotedHistory, ['version' => self::ALTER]);
		$steps = $this->migrator()->rollback();
		self::assertSame([self::BACKFILL], self::ids($steps));
		self::assertTrue($steps[0]->reverse);
		self::assertSame([self::CREATE], $this->history());
	}

	public function test_target_reconciles_reversals_and_older_pending_migrations(): void {
		$this->migrator()->migrate();
		$this->observer->delete($this->quotedHistory, ['version' => self::ALTER]);
		$steps = $this->migrator()->migrate(self::ALTER);
		self::assertSame([self::BACKFILL, self::ALTER], self::ids($steps));
		self::assertTrue($steps[0]->reverse);
		self::assertFalse($steps[1]->reverse);
		self::assertSame([self::CREATE, self::ALTER], $this->history());
	}

	public function test_target_rollback_leaves_older_pending_migrations_pending(): void {
		$this->migrator()->migrate();
		$this->observer->delete($this->quotedHistory, ['version' => self::ALTER]);

		$steps = $this->migrator()->rollbackTo(self::ALTER);

		self::assertSame([self::BACKFILL], self::ids($steps));
		self::assertTrue($steps[0]->reverse);
		self::assertSame([self::CREATE], $this->history());
		self::assertSame([], $this->migrator()->rollbackTo(self::ALTER));
	}

	public function test_unknown_rollback_target_is_rejected_without_creating_history(): void {
		try {
			$this->migrator()->rollbackTo('unknown');
			self::fail('Unknown rollback target must fail.');
		} catch (\InvalidArgumentException) {
			self::assertFalse($this->observer->createSchemaManager()->tablesExist([$this->historyName]));
		}
	}

	public function test_public_migration_workflow_uses_no_deprecated_dbal_api(): void {
		\Doctrine\Deprecations\Deprecation::enableTrackingDeprecations();

		try {
			$this->migrator()->preview();
			$this->migrator()->migrate();
			$this->migrator()->status();
			$this->migrator()->preview(Migrator::NONE);
			$this->migrator()->refresh();
			$this->migrator()->rollback(3);
			self::assertSame([], \Doctrine\Deprecations\Deprecation::getTriggeredDeprecations());
		} finally {
			\Doctrine\Deprecations\Deprecation::disable();
		}
	}

	public function test_guard_free_create_alter_and_data_step_apply_once(): void {
		$steps = $this->migrator()->migrate();

		self::assertSame([self::CREATE, self::ALTER, self::BACKFILL], self::ids($steps));
		self::assertCount(1, $steps[0]->sql, 'One CREATE TABLE statement');
		self::assertCount(1, $steps[1]->sql, 'One ALTER TABLE statement');
		self::assertSame([], $steps[2]->sql);
		self::assertTrue($steps[2]->hasDataStep);
		self::assertSame([self::CREATE, self::ALTER, self::BACKFILL], $this->history());

		$sql = $this->createTableSql();
		self::assertStringContainsString('`id` bigint unsigned not null auto_increment', str_replace('bigint(20)', 'bigint', $sql));
		self::assertStringContainsString('`amount` decimal(12,4) not null default', $sql);
		self::assertStringContainsString('`token` varbinary(16) default null', $sql);
		self::assertStringContainsString('`created_at` datetime(6) not null default current_timestamp(6)', $sql);
		self::assertStringContainsString('`updated_at` datetime(6) not null default current_timestamp(6) on update current_timestamp(6)', $sql);
		self::assertStringContainsString('`note` varchar(100)', $sql);
		self::assertStringContainsString('key `status` (`status`)', $sql);
		self::assertStringContainsString('charset=utf8mb4', $sql);

		self::assertSame([], $this->migrator()->migrate(), 'Nothing pending');
		self::assertSame([], $this->migrator()->preview(), 'A second run declares no DDL for timestamps, decimals, or binary columns');

		$status = $this->migrator()->status();
		self::assertSame(['applied', 'applied', 'applied'], array_map(static fn ($row): string => $row->state(), $status));
		self::assertSame('Add an optional note to entries', $status[1]->description);
		self::assertNotNull($status[0]->appliedAt);
	}

	public function test_created_tables_use_the_wordpress_collation(): void {
		// Choose a non-default collation so ignoring the option cannot pass by accident.
		$this->source->charset = 'utf8mb4';
		$this->source->collate = 'utf8mb4_bin';
		$this->migrator()->migrate(self::CREATE);

		self::assertSame('utf8mb4_bin', $this->observer->fetchOne(
			'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
			[$this->entriesName],
		));
		self::assertSame([], $this->migrator()->preview(self::CREATE));
	}

	public function test_data_step_runs_after_ddl_and_is_repeat_safe(): void {
		$this->migrator()->migrate(self::ALTER);
		$this->observer->insert($this->quotedEntries, ['name' => 'Blank', 'status' => '']);
		$this->observer->insert($this->quotedEntries, ['name' => 'Kept', 'status' => 'archived']);

		$this->migrator()->migrate();

		self::assertSame(['active', 'archived'], $this->observer->fetchFirstColumn('SELECT status FROM ' . $this->quotedEntries . ' ORDER BY id'));
	}

	public function test_retry_after_ledger_failure_records_without_repeating_ddl(): void {
		$this->migrator()->migrate(self::CREATE);
		$trigger = $this->rejectHistoryWrite('INSERT');

		try {
			$this->migrator()->migrate(self::ALTER);
			self::fail('Expected the ledger insert to fail.');
		} catch (\StellarWP\Foundation\Database\Migration\Exceptions\LedgerFailure $failure) {
			self::assertStringContainsString('History write failure', $failure->getPrevious()?->getMessage() ?? '');
		} finally {
			$this->observer->executeStatement('DROP TRIGGER ' . $trigger);
		}
		self::assertSame([self::CREATE], $this->history(), 'DDL committed but the ledger did not');
		$this->observer->insert($this->quotedEntries, ['name' => 'Survivor', 'status' => 'active', 'note' => 'Keep this']);

		$steps = $this->migrator()->migrate(self::ALTER);

		self::assertSame([self::ALTER], self::ids($steps));
		self::assertSame([], $steps[0]->sql, 'The retry detects the completed column and issues no DDL');
		self::assertSame([self::CREATE, self::ALTER], $this->history());
		self::assertSame('Keep this', $this->observer->fetchOne('SELECT note FROM ' . $this->quotedEntries));
	}

	public function test_create_retry_after_ledger_failure_preserves_rows(): void {
		$this->migrator()->migrate(Migrator::NONE); // Nothing to do, but creates the ledger so the trigger can be attached.
		$trigger = $this->rejectHistoryWrite('INSERT');

		try {
			$this->migrator()->migrate(self::CREATE);
			self::fail('Expected the ledger insert to fail.');
		} catch (\StellarWP\Foundation\Database\Migration\Exceptions\LedgerFailure) {
			// The table exists; history is empty.
		} finally {
			$this->observer->executeStatement('DROP TRIGGER ' . $trigger);
		}
		self::assertSame([], $this->history());
		$this->observer->insert($this->quotedEntries, ['name' => 'Survivor', 'status' => 'active']);

		$steps = $this->migrator()->migrate(self::CREATE);

		self::assertSame([], $steps[0]->sql);
		self::assertSame([self::CREATE], $this->history());
		self::assertSame(['Survivor'], $this->observer->fetchFirstColumn('SELECT name FROM ' . $this->quotedEntries));
		$table = $this->observer->createSchemaManager()->introspectTable($this->entriesName);
		self::assertSame('Application entries', $table->getComment());
		self::assertSame('Current processing state', $table->getColumn('status')->getComment());
	}

	public function test_undeclared_column_change_stops_the_run_and_names_the_column(): void {
		$this->migrator()->migrate(self::CREATE);
		$this->observer->executeStatement('ALTER TABLE ' . $this->quotedEntries . ' ADD note INT NOT NULL');

		try {
			$this->migrator()->migrate(self::ALTER);
			self::fail('Expected drift to be rejected.');
		} catch (IncompatibleSchema $failure) {
			self::assertStringContainsString(self::ALTER, $failure->getMessage());
			self::assertStringContainsString('column note differs from its declaration', $failure->getMessage());
		}
		self::assertSame([self::CREATE], $this->history());
		self::assertStringContainsString('`note` int', $this->createTableSql(), 'The runner never repaired the column silently');
	}

	public function test_incompatible_existing_table_stops_a_create_migration(): void {
		$this->observer->executeStatement("CREATE TABLE {$this->quotedEntries} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(49) NOT NULL) ENGINE=InnoDB");

		try {
			$this->migrator()->migrate(self::CREATE);
			self::fail('Expected the existing table to be rejected.');
		} catch (IncompatibleSchema $failure) {
			self::assertStringContainsString('differs from the declared initial definition', $failure->getMessage());
		}
		self::assertSame([], $this->history());
	}

	public function test_unrelated_columns_and_indexes_survive(): void {
		$this->migrator()->migrate(self::CREATE);
		$this->observer->executeStatement('ALTER TABLE ' . $this->quotedEntries . ' ADD external_value INT, ADD INDEX external_lookup (external_value)');

		$this->migrator()->migrate();

		$sql = $this->createTableSql();
		self::assertStringContainsString('`external_value` int', $sql);
		self::assertStringContainsString('key `external_lookup`', $sql);
		self::assertStringContainsString('`note` varchar(100)', $sql);
		self::assertSame([], $this->migrator()->preview());
	}

	public function test_preview_reports_exactly_the_sql_that_migrate_executes(): void {
		$preview  = $this->migrator()->preview();
		$executed = $this->migrator()->migrate();

		self::assertSame(self::ids($preview), self::ids($executed));
		self::assertSame(
			array_map(static fn (Step $step): array => $step->sql, $preview),
			array_map(static fn (Step $step): array => $step->sql, $executed),
		);
		self::assertSame([], $this->migrator()->preview());
	}

	public function test_rollback_by_step_and_by_target(): void {
		$this->migrator()->migrate();
		$this->observer->insert($this->quotedEntries, ['name' => 'Original', 'status' => 'active', 'note' => 'n']);

		$steps = $this->migrator()->rollback();
		self::assertSame([self::BACKFILL], self::ids($steps));
		self::assertTrue($steps[0]->reverse);
		self::assertSame([self::CREATE, self::ALTER], $this->history());

		$steps = $this->migrator()->rollbackTo(self::CREATE);
		self::assertSame([self::ALTER], self::ids($steps));
		self::assertStringNotContainsString('`note`', $this->createTableSql());
		self::assertSame(['Original'], $this->observer->fetchFirstColumn('SELECT name FROM ' . $this->quotedEntries));
		self::assertSame([self::CREATE], $this->history());

		$this->migrator()->rollbackTo(Migrator::NONE);
		self::assertSame([], $this->history());
		self::assertFalse($this->observer->createSchemaManager()->tablesExist([$this->entriesName]));
	}

	public function test_history_missing_a_dependency_is_reported_not_repaired(): void {
		$this->migrator()->migrate();
		$this->observer->delete($this->quotedHistory, ['version' => self::CREATE]);

		try {
			$this->migrator()->migrate(self::CREATE);
			self::fail('Expected the inconsistent ledger to be reported.');
		} catch (MigrationInterrupted $failure) {
			self::assertStringContainsString(self::ALTER, $failure->getMessage());
			self::assertStringContainsString('repair the ledger', $failure->getMessage());
		}
		self::assertSame([self::ALTER, self::BACKFILL], $this->history(), 'Nothing was executed or recorded');
	}

	public function test_another_session_holding_the_lock_blocks_the_run(): void {
		$name = hash('sha256', constant('DB_NAME') . "\0" . $this->historyName);
		self::assertSame(1, (int) $this->observer->fetchOne('SELECT GET_LOCK(?, 0)', [$name]));

		try {
			$this->migrator()->migrate();
			self::fail('Expected contention.');
		} catch (DatabaseException $failure) {
			self::assertInstanceOf(MigrationAlreadyRunning::class, $failure);
			self::assertFalse($this->observer->createSchemaManager()->tablesExist([$this->historyName]));
		} finally {
			$this->observer->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
		}
	}
}
