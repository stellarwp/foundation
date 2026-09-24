<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Migrations;

use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Migrations\Exceptions\IncompatibleSchema;
use StellarWP\Foundation\Migrations\Exceptions\MigrationAlreadyRunning;
use StellarWP\Foundation\Migrations\Exceptions\MigrationInterrupted;
use StellarWP\Foundation\Migrations\MigrationsProvider;
use StellarWP\Foundation\Migrations\Migrator;
use StellarWP\Foundation\Migrations\ValueObjects\MigrationRegistration;
use StellarWP\Foundation\Migrations\ValueObjects\Step;
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
		return ['migrations' => ['table' => $this->suffix . '_history']];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->container->register(MigrationsProvider::class);
		$this->entries       = new EntriesTable($this->suffix . '_entries');
		$this->entriesName   = $this->source->prefix . $this->suffix . '_entries';
		$this->quotedEntries = $this->privateTable($this->suffix . '_entries');
		$this->historyName   = $this->source->prefix . $this->suffix . '_history';
		$this->quotedHistory = $this->privateTable($this->suffix . '_history');

		// What an application's Database_Provider does: contribute migrations lazily.
		$this->container->singleton(EntriesTable::class, $this->entries);
		$this->container->when(BackfillEntryStatus::class)->needs('$physicalName')->give($this->entriesName);
		$this->container->mergeArrayVar(MigrationsProvider::MIGRATIONS, static fn (C $c): array => [
			new MigrationRegistration(BackfillEntryStatus::ID, $c->get(BackfillEntryStatus::class)),
			new MigrationRegistration(AddEntryNote::ID, $c->get(AddEntryNote::class)),
			new MigrationRegistration(CreateEntries::ID, $c->get(CreateEntries::class)),
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
		$this->assertIsArray($row);

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
		$this->assertInstanceOf(\StellarWP\Foundation\Migrations\ValueObjects\MigrationStatus::class, $last);
		$this->assertSame('missing', $last->state());
		$this->expectException(MigrationInterrupted::class);
		$this->migrator()->migrate();
	}

	public function test_rollback_on_an_empty_history_has_no_work(): void {
		$this->assertSame([], $this->migrator()->rollback());
		$this->assertFalse($this->observer->createSchemaManager()->tablesExist([$this->historyName]));
	}

	public function test_nonpositive_rollback_steps_are_rejected_without_initializing_history(): void {
		try {
			$this->migrator()->rollback(0);
			$this->fail('Invalid rollback count must fail.');
		} catch (\InvalidArgumentException) {
			$this->assertFalse($this->observer->createSchemaManager()->tablesExist([$this->historyName]));
		}
	}

	public function test_unknown_target_is_rejected_before_creating_history(): void {
		try {
			$this->migrator()->migrate('unknown');
			$this->fail('Unknown target must fail.');
		} catch (\InvalidArgumentException) {
			$this->assertFalse($this->observer->createSchemaManager()->tablesExist([$this->historyName]));
		}
	}

	public function test_step_rollback_does_not_apply_an_older_pending_migration(): void {
		$this->migrator()->migrate();
		$this->observer->delete($this->quotedHistory, ['version' => self::ALTER]);
		$steps = $this->migrator()->rollback();
		$this->assertSame([self::BACKFILL], self::ids($steps));
		$this->assertTrue($steps[0]->reverse);
		$this->assertSame([self::CREATE], $this->history());
	}

	public function test_target_reconciles_reversals_and_older_pending_migrations(): void {
		$this->migrator()->migrate();
		$this->observer->delete($this->quotedHistory, ['version' => self::ALTER]);
		$steps = $this->migrator()->migrate(self::ALTER);
		$this->assertSame([self::BACKFILL, self::ALTER], self::ids($steps));
		$this->assertTrue($steps[0]->reverse);
		$this->assertFalse($steps[1]->reverse);
		$this->assertSame([self::CREATE, self::ALTER], $this->history());
	}

	public function test_target_rollback_leaves_older_pending_migrations_pending(): void {
		$this->migrator()->migrate();
		$this->observer->delete($this->quotedHistory, ['version' => self::ALTER]);

		$steps = $this->migrator()->rollbackTo(self::ALTER);

		$this->assertSame([self::BACKFILL], self::ids($steps));
		$this->assertTrue($steps[0]->reverse);
		$this->assertSame([self::CREATE], $this->history());
		$this->assertSame([], $this->migrator()->rollbackTo(self::ALTER));
	}

	public function test_unknown_rollback_target_is_rejected_without_creating_history(): void {
		try {
			$this->migrator()->rollbackTo('unknown');
			$this->fail('Unknown rollback target must fail.');
		} catch (\InvalidArgumentException) {
			$this->assertFalse($this->observer->createSchemaManager()->tablesExist([$this->historyName]));
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
			$this->assertSame([], \Doctrine\Deprecations\Deprecation::getTriggeredDeprecations());
		} finally {
			\Doctrine\Deprecations\Deprecation::disable();
		}
	}

	public function test_guard_free_create_alter_and_data_step_apply_once(): void {
		$steps = $this->migrator()->migrate();

		$this->assertSame([self::CREATE, self::ALTER, self::BACKFILL], self::ids($steps));
		$this->assertCount(1, $steps[0]->sql, 'One CREATE TABLE statement');
		$this->assertCount(1, $steps[1]->sql, 'One ALTER TABLE statement');
		$this->assertSame([], $steps[2]->sql);
		$this->assertTrue($steps[2]->hasDataStep);
		$this->assertSame([self::CREATE, self::ALTER, self::BACKFILL], $this->history());

		$sql = $this->createTableSql();
		$this->assertStringContainsString('`id` bigint unsigned not null auto_increment', str_replace('bigint(20)', 'bigint', $sql));
		$this->assertStringContainsString('`amount` decimal(12,4) not null default', $sql);
		$this->assertStringContainsString('`token` varbinary(16) default null', $sql);
		$this->assertStringContainsString('`created_at` datetime(6) not null default current_timestamp(6)', $sql);
		$this->assertStringContainsString('`updated_at` datetime(6) not null default current_timestamp(6) on update current_timestamp(6)', $sql);
		$this->assertStringContainsString('`note` varchar(100)', $sql);
		$this->assertStringContainsString('key `status` (`status`)', $sql);
		$this->assertStringContainsString('charset=utf8mb4', $sql);

		$this->assertSame([], $this->migrator()->migrate(), 'Nothing pending');
		$this->assertSame([], $this->migrator()->preview(), 'A second run declares no DDL for timestamps, decimals, or binary columns');

		$status = $this->migrator()->status();
		$this->assertSame(['applied', 'applied', 'applied'], array_map(static fn ($row): string => $row->state(), $status));
		$this->assertSame('Add an optional note to entries', $status[1]->description);
		$this->assertNotNull($status[0]->appliedAt);
	}

	public function test_created_tables_use_the_wordpress_collation(): void {
		// Choose a non-default collation so ignoring the option cannot pass by accident.
		$this->source->charset = 'utf8mb4';
		$this->source->collate = 'utf8mb4_bin';
		$this->migrator()->migrate(self::CREATE);

		$this->assertSame('utf8mb4_bin', $this->observer->fetchOne(
			'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
			[$this->entriesName],
		));
		$this->assertSame([], $this->migrator()->preview(self::CREATE));
	}

	public function test_data_step_runs_after_ddl_and_is_repeat_safe(): void {
		$this->migrator()->migrate(self::ALTER);
		$this->observer->insert($this->quotedEntries, ['name' => 'Blank', 'status' => '']);
		$this->observer->insert($this->quotedEntries, ['name' => 'Kept', 'status' => 'archived']);

		$this->migrator()->migrate();

		$this->assertSame(['active', 'archived'], $this->observer->fetchFirstColumn('SELECT status FROM ' . $this->quotedEntries . ' ORDER BY id'));
	}

	public function test_retry_after_ledger_failure_records_without_repeating_ddl(): void {
		$this->migrator()->migrate(self::CREATE);
		$trigger = $this->rejectHistoryWrite('INSERT');

		try {
			$this->migrator()->migrate(self::ALTER);
			$this->fail('Expected the ledger insert to fail.');
		} catch (\StellarWP\Foundation\Migrations\Exceptions\LedgerFailure $failure) {
			$this->assertStringContainsString('History write failure', $failure->getPrevious()?->getMessage() ?? '');
		} finally {
			$this->observer->executeStatement('DROP TRIGGER ' . $trigger);
		}
		$this->assertSame([self::CREATE], $this->history(), 'DDL committed but the ledger did not');
		$this->observer->insert($this->quotedEntries, ['name' => 'Survivor', 'status' => 'active', 'note' => 'Keep this']);

		$steps = $this->migrator()->migrate(self::ALTER);

		$this->assertSame([self::ALTER], self::ids($steps));
		$this->assertSame([], $steps[0]->sql, 'The retry detects the completed column and issues no DDL');
		$this->assertSame([self::CREATE, self::ALTER], $this->history());
		$this->assertSame('Keep this', $this->observer->fetchOne('SELECT note FROM ' . $this->quotedEntries));
	}

	public function test_create_retry_after_ledger_failure_preserves_rows(): void {
		$this->migrator()->migrate(Migrator::NONE); // Nothing to do, but creates the ledger so the trigger can be attached.
		$trigger = $this->rejectHistoryWrite('INSERT');

		try {
			$this->migrator()->migrate(self::CREATE);
			$this->fail('Expected the ledger insert to fail.');
		} catch (\StellarWP\Foundation\Migrations\Exceptions\LedgerFailure) {
			// The table exists; history is empty.
		} finally {
			$this->observer->executeStatement('DROP TRIGGER ' . $trigger);
		}
		$this->assertSame([], $this->history());
		$this->observer->insert($this->quotedEntries, ['name' => 'Survivor', 'status' => 'active']);

		$steps = $this->migrator()->migrate(self::CREATE);

		$this->assertSame([], $steps[0]->sql);
		$this->assertSame([self::CREATE], $this->history());
		$this->assertSame(['Survivor'], $this->observer->fetchFirstColumn('SELECT name FROM ' . $this->quotedEntries));
		$table = $this->observer->createSchemaManager()->introspectTable($this->entriesName);
		$this->assertSame('Application entries', $table->getComment());
		$this->assertSame('Current processing state', $table->getColumn('status')->getComment());
	}

	public function test_undeclared_column_change_stops_the_run_and_names_the_column(): void {
		$this->migrator()->migrate(self::CREATE);
		$this->observer->executeStatement('ALTER TABLE ' . $this->quotedEntries . ' ADD note INT NOT NULL');

		try {
			$this->migrator()->migrate(self::ALTER);
			$this->fail('Expected drift to be rejected.');
		} catch (IncompatibleSchema $failure) {
			$this->assertStringContainsString(self::ALTER, $failure->getMessage());
			$this->assertStringContainsString('column note differs from its declaration', $failure->getMessage());
		}
		$this->assertSame([self::CREATE], $this->history());
		$this->assertStringContainsString('`note` int', $this->createTableSql(), 'The runner never repaired the column silently');
	}

	public function test_incompatible_existing_table_stops_a_create_migration(): void {
		$this->observer->executeStatement("CREATE TABLE {$this->quotedEntries} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(49) NOT NULL) ENGINE=InnoDB");

		try {
			$this->migrator()->migrate(self::CREATE);
			$this->fail('Expected the existing table to be rejected.');
		} catch (IncompatibleSchema $failure) {
			$this->assertStringContainsString('differs from the declared initial definition', $failure->getMessage());
		}
		$this->assertSame([], $this->history());
	}

	public function test_unrelated_columns_and_indexes_survive(): void {
		$this->migrator()->migrate(self::CREATE);
		$this->observer->executeStatement('ALTER TABLE ' . $this->quotedEntries . ' ADD external_value INT, ADD INDEX external_lookup (external_value)');

		$this->migrator()->migrate();

		$sql = $this->createTableSql();
		$this->assertStringContainsString('`external_value` int', $sql);
		$this->assertStringContainsString('key `external_lookup`', $sql);
		$this->assertStringContainsString('`note` varchar(100)', $sql);
		$this->assertSame([], $this->migrator()->preview());
	}

	public function test_preview_reports_exactly_the_sql_that_migrate_executes(): void {
		$preview  = $this->migrator()->preview();
		$executed = $this->migrator()->migrate();

		$this->assertSame(self::ids($preview), self::ids($executed));
		$this->assertSame(
			array_map(static fn (Step $step): array => $step->sql, $preview),
			array_map(static fn (Step $step): array => $step->sql, $executed),
		);
		$this->assertSame([], $this->migrator()->preview());
	}

	public function test_rollback_by_step_and_by_target(): void {
		$this->migrator()->migrate();
		$this->observer->insert($this->quotedEntries, ['name' => 'Original', 'status' => 'active', 'note' => 'n']);

		$steps = $this->migrator()->rollback();
		$this->assertSame([self::BACKFILL], self::ids($steps));
		$this->assertTrue($steps[0]->reverse);
		$this->assertSame([self::CREATE, self::ALTER], $this->history());

		$steps = $this->migrator()->rollbackTo(self::CREATE);
		$this->assertSame([self::ALTER], self::ids($steps));
		$this->assertStringNotContainsString('`note`', $this->createTableSql());
		$this->assertSame(['Original'], $this->observer->fetchFirstColumn('SELECT name FROM ' . $this->quotedEntries));
		$this->assertSame([self::CREATE], $this->history());

		$this->migrator()->rollbackTo(Migrator::NONE);
		$this->assertSame([], $this->history());
		$this->assertFalse($this->observer->createSchemaManager()->tablesExist([$this->entriesName]));
	}

	public function test_history_missing_a_dependency_is_reported_not_repaired(): void {
		$this->migrator()->migrate();
		$this->observer->delete($this->quotedHistory, ['version' => self::CREATE]);

		try {
			$this->migrator()->migrate(self::CREATE);
			$this->fail('Expected the inconsistent ledger to be reported.');
		} catch (MigrationInterrupted $failure) {
			$this->assertStringContainsString(self::ALTER, $failure->getMessage());
			$this->assertStringContainsString('repair the ledger', $failure->getMessage());
		}
		$this->assertSame([self::ALTER, self::BACKFILL], $this->history(), 'Nothing was executed or recorded');
	}

	public function test_another_session_holding_the_lock_blocks_the_run(): void {
		$name = hash('sha256', constant('DB_NAME') . "\0" . $this->historyName);
		$this->assertSame(1, (int) $this->observer->fetchOne('SELECT GET_LOCK(?, 0)', [$name]));

		try {
			$this->migrator()->migrate();
			$this->fail('Expected contention.');
		} catch (DatabaseException $failure) {
			$this->assertInstanceOf(MigrationAlreadyRunning::class, $failure);
			$this->assertFalse($this->observer->createSchemaManager()->tablesExist([$this->historyName]));
		} finally {
			$this->observer->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
		}
	}
}
