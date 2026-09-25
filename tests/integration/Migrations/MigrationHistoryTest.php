<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Migrations;

use InvalidArgumentException;
use RuntimeException;
use StellarWP\Foundation\Migrations\Contracts\DescribesMigration;
use StellarWP\Foundation\Migrations\Contracts\MigratesData;
use StellarWP\Foundation\Migrations\Contracts\Migration;
use StellarWP\Foundation\Migrations\DataMigrationContext;
use StellarWP\Foundation\Migrations\Exceptions\InvalidMigrationId;
use StellarWP\Foundation\Migrations\Exceptions\MigrationAlreadyRunning;
use StellarWP\Foundation\Migrations\MigrationsProvider;
use StellarWP\Foundation\Migrations\Migrator;
use StellarWP\Foundation\Migrations\Schema\Blueprint;
use StellarWP\Foundation\Migrations\ValueObjects\MigrationRegistration;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\AddEntryNote;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\CreateEntries;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\EntriesTable;
use Throwable;

/**
 * Exercise public history repair against real storage and migration locking.
 */
final class MigrationHistoryTest extends DatabaseTestCase
{
	private string $historyName;
	private string $quotedHistory;

	/**
	 * Give each test its own application ledger.
	 */
	protected function configuration(): array {
		return [
			'migrations' => [
				'table' => $this->suffix . '_history',
			],
		];
	}

	/**
	 * Wire the production provider and track its ledger for cleanup.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container->register(MigrationsProvider::class);
		$this->historyName   = $this->source->prefix . $this->suffix . '_history';
		$this->quotedHistory = $this->privateTable($this->suffix . '_history');
	}

	/**
	 * An adopted table keeps its data while later declarations execute normally.
	 */
	public function test_adoption_preserves_rows_and_allows_later_real_alteration(): void {
		$table  = new EntriesTable($this->suffix);
		$runner = $this->runner(
			new MigrationRegistration(CreateEntries::ID, new CreateEntries($table)),
			new MigrationRegistration(AddEntryNote::ID, new AddEntryNote($table)),
		);

		$runner->markApplied(CreateEntries::ID);
		$this->assertOriginal();
		$steps = $runner->migrate();
		$this->assertCount(1, $steps);
		$this->assertSame(AddEntryNote::ID, $steps[0]->id);
		$this->assertTrue($this->observer->createSchemaManager()->introspectTable($this->source->prefix . $this->suffix)->hasColumn('note'));
		$this->assertOriginal();
		$this->assertSame([], $runner->migrate());
	}

	/**
	 * Marking never evaluates migration declarations, descriptions, or data callbacks.
	 */
	public function test_marking_skips_all_application_callbacks(): void {
		$runner = $this->runner(new MigrationRegistration('one', $this->unexecutableMigration()));

		$runner->markApplied('one');
		$this->assertSame([
			'one',
		], array_keys($this->history()));
		$runner->markPending('one');
		$this->assertSame([], $this->history());
		$runner->markAllApplied();
		$this->assertSame([
			'one',
		], array_keys($this->history()));
		$this->assertOriginal();
	}

	/**
	 * Repeated adoption preserves the original timestamp and removal is repeatable.
	 */
	public function test_repeated_marking_preserves_timestamps(): void {
		$runner = $this->runner(new MigrationRegistration('one', $this->unexecutableMigration()));
		$runner->markApplied('one');
		$this->observer->update($this->quotedHistory, [
			'applied_at' => '2001-02-03 04:05:06.123456',
		], [
			'version' => 'one',
		]);
		$original = $this->history();

		$runner->markApplied('one');
		$runner->markAllApplied();
		$this->assertSame($original, $this->history());
		$runner->markPending('one');
		$runner->markPending('one');
		$this->assertSame([], $this->history());
	}

	/**
	 * The all-pending operation only inserts registered identities missing from history.
	 */
	public function test_mark_all_preserves_existing_and_unregistered_history(): void {
		$migration = $this->unexecutableMigration();
		$runner    = $this->runner(
			new MigrationRegistration('first', $migration),
			new MigrationRegistration('second', $migration),
			new MigrationRegistration('third', $migration),
		);
		$runner->markApplied('second');
		$this->observer->insert($this->quotedHistory, [
			'version'    => 'missing',
			'applied_at' => '2001-02-03 04:05:06.123456',
		]);
		$existing = $this->history();

		$runner->markAllApplied();
		$applied = $this->history();
		$this->assertSame([
			'first',
			'missing',
			'second',
			'third',
		], array_keys($applied));
		$this->assertSame($existing['second'], $applied['second']);
		$this->assertSame($existing['missing'], $applied['missing']);
		$runner->markAllApplied();
		$this->assertSame($applied, $this->history());
	}

	/**
	 * A failure after the first batch insert cannot leave a partially adopted history.
	 */
	public function test_mark_all_rolls_back_earlier_inserts_when_a_later_insert_fails(): void {
		$migration = $this->unexecutableMigration();
		$runner    = $this->runner(
			new MigrationRegistration('first', $migration),
			new MigrationRegistration('second', $migration),
			new MigrationRegistration('third', $migration),
		);
		$runner->markApplied('third');
		$existing = $this->history();
		$trigger  = $this->observer->getDatabasePlatform()->quoteSingleIdentifier($this->suffix . '_reject_second');
		$this->observer->executeStatement("CREATE TRIGGER {$trigger}
			BEFORE INSERT ON {$this->quotedHistory}
			FOR EACH ROW
			BEGIN
				IF NEW.version = 'second' THEN
					SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Second history insert rejected';
				END IF;
			END");

		try {
			$runner->markAllApplied();
			$this->fail('The second insert must fail.');
		} catch (Throwable $failure) {
			$this->assertStringContainsString('Second history insert rejected', $failure->getMessage());
		} finally {
			$this->observer->executeStatement('DROP TRIGGER ' . $trigger);
		}

		$this->assertSame($existing, $this->history());
		$this->assertFalse($this->db->isTransactionActive());
		$runner->markAllApplied();
		$this->assertCount(3, $this->history());
	}

	/**
	 * Exact identifiers preserve case distinctions and PHP numeric key handling.
	 */
	public function test_marking_uses_exact_case_sensitive_and_numeric_ids(): void {
		$migration = $this->unexecutableMigration();
		$runner    = $this->runner(
			new MigrationRegistration('12', $migration),
			new MigrationRegistration('Case', $migration),
			new MigrationRegistration('case', $migration),
		);

		$runner->markApplied('12');
		$runner->markApplied('Case');
		$this->assertSame([
			'12',
			'Case',
		], array_map('strval', array_keys($this->history())));
		$runner->markAllApplied();
		$runner->markPending('Case');
		$this->assertSame([
			'12',
			'case',
		], array_map('strval', array_keys($this->history())));
		$runner->markPending('12');
		$this->assertSame([
			'case',
		], array_keys($this->history()));
	}

	/**
	 * Applied records can be removed even when their declarations are unavailable.
	 */
	public function test_mark_pending_removes_ledger_only_ids_idempotently(): void {
		$runner = $this->runner(new MigrationRegistration('one', $this->unexecutableMigration()));
		$runner->markApplied('one');
		$this->observer->insert($this->quotedHistory, [
			'version' => 'missing',
		]);

		$runner->markPending('missing');
		$runner->markPending('missing');
		$this->assertSame([
			'one',
		], array_keys($this->history()));
	}

	/**
	 * Removing absent history does not create a ledger.
	 */
	public function test_mark_pending_without_history_does_not_create_storage(): void {
		$runner = $this->runner();
		$runner->markPending('missing');
		$runner->markPending('missing');
		$this->assertHistoryAbsent();
	}

	/**
	 * An empty registration set has no pending records to adopt.
	 */
	public function test_mark_all_without_registered_migrations_leaves_storage_absent(): void {
		$runner = $this->runner();
		$runner->markAllApplied();
		$this->assertHistoryAbsent();
		$this->assertOriginal();
	}

	/**
	 * A manually reversed migration can execute again once its history is removed.
	 */
	public function test_manual_reversal_can_be_marked_pending_and_applied_again(): void {
		$table  = new EntriesTable($this->suffix);
		$runner = $this->runner(
			new MigrationRegistration(CreateEntries::ID, new CreateEntries($table)),
			new MigrationRegistration(AddEntryNote::ID, new AddEntryNote($table)),
		);
		$runner->markApplied(CreateEntries::ID);
		$runner->migrate();
		$this->observer->executeStatement("ALTER TABLE {$this->table} DROP COLUMN note");

		$runner->markPending(AddEntryNote::ID);
		$steps = $runner->migrate();
		$this->assertCount(1, $steps);
		$this->assertSame(AddEntryNote::ID, $steps[0]->id);
		$this->assertTrue($this->observer->createSchemaManager()->introspectTable($this->source->prefix . $this->suffix)->hasColumn('note'));
		$this->assertOriginal();
	}

	/**
	 * An adoption request must identify a registered migration.
	 */
	public function test_mark_applied_rejects_unknown_ids_without_creating_history(): void {
		try {
			$this->runner()->markApplied('missing');
			$this->fail('Unregistered migrations cannot be marked applied.');
		} catch (InvalidArgumentException) {
			$this->assertHistoryAbsent();
		}
	}

	/**
	 * The removal boundary rejects invalid ledger identities and target aliases.
	 */
	public function test_mark_pending_rejects_invalid_ids_without_creating_history(): void {
		$runner = $this->runner();

		foreach ([
			'',
			' padded',
			'0',
			'latest',
			str_repeat('x', 192),
		] as $id) {
			try {
				$runner->markPending($id);
				$this->fail('Invalid history identities must be rejected.');
			} catch (InvalidMigrationId) {
				$this->assertHistoryAbsent();
			}
		}
	}

	/**
	 * Adoption retains the ordinary rollback behavior of the registered declaration.
	 */
	public function test_marked_migration_is_reversed_by_normal_rollback(): void {
		$runner = $this->runner(new MigrationRegistration(CreateEntries::ID, new CreateEntries(new EntriesTable($this->suffix))));
		$runner->markApplied(CreateEntries::ID);

		$steps = $runner->rollback();
		$this->assertCount(1, $steps);
		$this->assertSame(CreateEntries::ID, $steps[0]->id);
		$this->assertTrue($steps[0]->reverse);
		$this->assertFalse($this->observer->createSchemaManager()->tablesExist([
			$this->source->prefix . $this->suffix,
		]));
		$this->assertSame([], $this->history());
	}

	/**
	 * Every history mutation competes for the same lock as normal migrations.
	 */
	public function test_another_session_holding_the_lock_blocks_each_marking_operation(): void {
		$runner = $this->runner(new MigrationRegistration('one', $this->unexecutableMigration()));
		$name   = hash('sha256', constant('DB_NAME') . "\0" . $this->historyName);
		$this->assertSame(1, (int) $this->observer->fetchOne('SELECT GET_LOCK(?, 0)', [
			$name,
		]));

		try {
			foreach ([
				static fn () => $runner->markApplied('one'),
				static fn () => $runner->markAllApplied(),
				static fn () => $runner->markPending('one'),
			] as $operation) {
				try {
					$operation();
					$this->fail('History mutation must fail during contention.');
				} catch (MigrationAlreadyRunning) {
					$this->assertHistoryAbsent();
				}
			}
		} finally {
			$this->observer->fetchOne('SELECT RELEASE_LOCK(?)', [
				$name,
			]);
		}
	}

	private function runner(MigrationRegistration ...$registrations): Migrator {
		$this->container->mergeArrayVar(MigrationsProvider::MIGRATIONS, static fn (): array => $registrations);

		return $this->container->get(Migrator::class);
	}

	/**
	 * @return array<string, string>
	 */
	private function history(): array {
		return $this->observer->fetchAllKeyValue("SELECT
				version,
				applied_at
			FROM {$this->quotedHistory}
			ORDER BY version");
	}

	private function assertHistoryAbsent(): void {
		$this->assertFalse($this->observer->createSchemaManager()->tablesExist([
			$this->historyName,
		]));
	}

	private function unexecutableMigration(): Migration {
		return new class implements DescribesMigration, MigratesData, Migration {
			/**
			 * Fail if marking evaluates a forward declaration.
			 */
			public function up(Blueprint $schema): void {
				throw new RuntimeException('Marking must not call up().');
			}

			/**
			 * Fail if marking evaluates a reverse declaration.
			 */
			public function down(Blueprint $schema): void {
				throw new RuntimeException('Marking must not call down().');
			}

			/**
			 * Fail if marking executes data migration work.
			 */
			public function migrate(DataMigrationContext $context): void {
				throw new RuntimeException('Marking must not call migrate().');
			}

			/**
			 * Fail if marking evaluates a display description.
			 */
			public function describe(): string {
				throw new RuntimeException('Marking must not call describe().');
			}
		};
	}
}
