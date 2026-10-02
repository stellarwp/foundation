<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Migrations;

use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\Migrations\Exceptions\IrreversibleMigration;
use StellarWP\Foundation\Migrations\Exceptions\MigrationAlreadyRunning;
use StellarWP\Foundation\Migrations\Exceptions\MigrationInterrupted;
use StellarWP\Foundation\Migrations\MigrationsProvider;
use StellarWP\Foundation\Migrations\Migrator;
use StellarWP\Foundation\Migrations\ValueObjects\MigrationRegistration;
use StellarWP\Foundation\Migrations\ValueObjects\Step;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\AddEntryArchivedFlag;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\AddEntryNote;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\CreateEntries;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\CreateEntryTags;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\DropEntryTags;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\EntriesTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\IndexEntryNote;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\RecreateEntryTags;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\ReduceCreatedAtPrecision;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\ReplaceNoteLookup;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\TagsTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\TrackEntryUpdates;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative\WidenEntryName;

/**
 * Recovery and preservation guarantees: down(), foreign keys, change(), timestamps, preview.
 */
final class DeclarativeMigratorEdgeCaseTest extends DatabaseTestCase
{
	private string $quotedEntries;
	private string $historyName;

	protected function configuration(): array {
		return ['migrations' => ['table' => $this->suffix . '_history']];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->container->register(MigrationsProvider::class);
		$this->quotedEntries = $this->privateTable($this->suffix . '_entries');
		$this->privateTable($this->suffix . '_tags');
		$this->historyName = $this->source->prefix . $this->suffix . '_history';
		$this->privateTable($this->suffix . '_history');

		$this->container->singleton(EntriesTable::class, new EntriesTable($this->suffix . '_entries'));
		$this->container->singleton(TagsTable::class, new TagsTable($this->suffix . '_tags'));
		$this->container->mergeArrayVar(MigrationsProvider::MIGRATIONS, static fn (C $c): array => [
			new MigrationRegistration(ReduceCreatedAtPrecision::ID, $c->get(ReduceCreatedAtPrecision::class)),
			new MigrationRegistration(TrackEntryUpdates::ID, $c->get(TrackEntryUpdates::class)),
			new MigrationRegistration(WidenEntryName::ID, $c->get(WidenEntryName::class)),
			new MigrationRegistration(AddEntryArchivedFlag::ID, $c->get(AddEntryArchivedFlag::class)),
			new MigrationRegistration(ReplaceNoteLookup::ID, $c->get(ReplaceNoteLookup::class)),
			new MigrationRegistration(IndexEntryNote::ID, $c->get(IndexEntryNote::class)),
			new MigrationRegistration(AddEntryNote::ID, $c->get(AddEntryNote::class)),
			new MigrationRegistration(RecreateEntryTags::ID, $c->get(RecreateEntryTags::class)),
			new MigrationRegistration(DropEntryTags::ID, $c->get(DropEntryTags::class)),
			new MigrationRegistration(CreateEntryTags::ID, $c->get(CreateEntryTags::class)),
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
		return $this->observer->fetchFirstColumn('SELECT version FROM `' . $this->historyName . '` ORDER BY version');
	}

	private function createTableSql(): string {
		$row = $this->observer->fetchAssociative('SHOW CREATE TABLE ' . $this->quotedEntries);
		$this->assertIsArray($row);

		return strtolower((string) $row['Create Table']);
	}

	public function test_rollback_honors_an_irreversible_down(): void {
		$this->migrator()->migrate(AddEntryArchivedFlag::ID);
		$this->assertStringContainsString('`archived`', $this->createTableSql());

		try {
			$this->migrator()->rollback();
			$this->fail('Expected the author\'s refusal to reverse.');
		} catch (IrreversibleMigration $failure) {
			$this->assertStringContainsString(AddEntryArchivedFlag::ID, $failure->getMessage());
		}
		$this->assertContains(AddEntryArchivedFlag::ID, $this->history(), 'The refused migration stays recorded');
		$this->assertStringContainsString('`archived`', $this->createTableSql(), 'No destructive DDL was reconstructed from up()');
	}

	public function test_unrelated_foreign_key_survives_an_alteration(): void {
		$this->migrator()->migrate(CreateEntries::ID);
		$this->observer->executeStatement("ALTER TABLE {$this->quotedEntries} ADD parent_id INT NULL, ADD CONSTRAINT fk_parent FOREIGN KEY (parent_id) REFERENCES {$this->table} (id)");

		$steps = $this->migrator()->migrate(AddEntryNote::ID);

		foreach ($steps as $step) {
			$this->assertStringNotContainsStringIgnoringCase('foreign key', implode("\n", $step->sql));
		}
		$this->assertStringContainsString('constraint `fk_parent` foreign key', $this->createTableSql());
		$this->assertStringContainsString('`note`', $this->createTableSql());
	}

	public function test_change_replaces_the_complete_column_definition_and_down_restores_it(): void {
		$this->migrator()->migrate(WidenEntryName::ID);
		$column = $this->observer->createSchemaManager()->introspectTable(trim($this->quotedEntries, '`'))->getColumn('name');
		$this->assertSame(120, $column->getLength());
		$this->assertFalse($column->getNotnull());
		$this->assertStringContainsString("comment='imported entries'", $this->createTableSql());

		$steps = $this->migrator()->rollback();

		$this->assertSame([WidenEntryName::ID], array_map(static fn (Step $step): string => $step->id, $steps));
		$column = $this->observer->createSchemaManager()->introspectTable(trim($this->quotedEntries, '`'))->getColumn('name');
		$this->assertSame(50, $column->getLength());
		$this->assertTrue($column->getNotnull());
		$this->assertStringNotContainsString('imported entries', $this->createTableSql());
	}

	public function test_timestamp_attribute_changes_emit_sql_and_rerun_cleanly(): void {
		$this->migrator()->migrate(WidenEntryName::ID);
		$this->assertStringNotContainsString('`created_at` datetime(6) not null default current_timestamp(6) on update', $this->createTableSql());

		$steps = $this->migrator()->migrate(TrackEntryUpdates::ID);

		$last = end($steps);
		$this->assertInstanceOf(Step::class, $last);
		$this->assertSame(TrackEntryUpdates::ID, $last->id);
		$this->assertCount(1, $last->sql);
		$this->assertStringContainsStringIgnoringCase('MODIFY', $last->sql[0]);
		$this->assertStringContainsString('`created_at` datetime(6) not null default current_timestamp(6) on update current_timestamp(6)', $this->createTableSql());
		$this->assertSame([], $this->migrator()->preview(TrackEntryUpdates::ID), 'Attributes DBAL cannot compare still produce no DDL on rerun');

		$this->migrator()->rollback();
		$this->assertStringContainsString('`created_at` datetime(6) not null default current_timestamp(6),', $this->createTableSql());
	}

	public function test_unrelated_timestamp_changes_are_preserved(): void {
		$this->migrator()->migrate(AddEntryArchivedFlag::ID);
		$this->observer->executeStatement("ALTER TABLE {$this->quotedEntries} MODIFY created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)");
		$this->migrator()->migrate(WidenEntryName::ID);
		$this->assertContains(WidenEntryName::ID, $this->history());
		$this->assertStringContainsString('on update current_timestamp(6)', $this->createTableSql());
	}

	public function test_preview_sees_existing_tables_from_later_steps_and_creates_no_ledger(): void {
		$preview = $this->migrator()->preview();

		$this->assertSame(
			[CreateEntries::ID, CreateEntryTags::ID, DropEntryTags::ID, RecreateEntryTags::ID, AddEntryNote::ID, IndexEntryNote::ID, ReplaceNoteLookup::ID, AddEntryArchivedFlag::ID, WidenEntryName::ID, TrackEntryUpdates::ID, ReduceCreatedAtPrecision::ID],
			array_map(static fn (Step $step): string => $step->id, $preview),
		);
		$this->assertCount(1, $preview[0]->sql, 'Entries is created');
		$this->assertStringContainsStringIgnoringCase('CREATE TABLE', $preview[1]->sql[0]);
		$this->assertStringContainsStringIgnoringCase('DROP TABLE', $preview[2]->sql[0]);
		$this->assertStringContainsStringIgnoringCase('CREATE TABLE', $preview[3]->sql[0], 'Preview remembers the simulated drop and does not reload the old table');
		$this->assertFalse($this->observer->createSchemaManager()->tablesExist([$this->historyName]), 'Preview executes nothing, not even ledger creation');

		$executed = $this->migrator()->migrate();
		$this->assertSame(
			array_map(static fn (Step $step): array => $step->sql, $preview),
			array_map(static fn (Step $step): array => $step->sql, $executed),
		);
	}

	public function test_interrupted_index_replacement_stops_without_recording_success(): void {
		$this->migrator()->migrate(IndexEntryNote::ID);
		$this->observer->executeStatement("DROP INDEX note_lookup ON {$this->quotedEntries}");

		try {
			$this->migrator()->migrate(ReplaceNoteLookup::ID);
			$this->fail('A missing index requires inspection before retry.');
		} catch (MigrationInterrupted $failure) {
			$this->assertStringContainsString('note_lookup does not exist', $failure->getMessage());
		}

		$this->assertNotContains(ReplaceNoteLookup::ID, $this->history());
	}

	public function test_conflicting_existing_index_stops_an_addition(): void {
		$this->migrator()->migrate(AddEntryNote::ID);
		$this->observer->executeStatement("CREATE UNIQUE INDEX note_lookup ON {$this->quotedEntries} (name)");

		try {
			$this->migrator()->migrate(IndexEntryNote::ID);
			$this->fail('Expected the conflicting index to be rejected.');
		} catch (MigrationInterrupted $failure) {
			$this->assertStringContainsString('Index note_lookup already exists', $failure->getMessage());
		}
		$this->assertStringContainsString('unique key `note_lookup` (`name`)', $this->createTableSql(), 'The uniqueness guarantee was not replaced');
		$this->assertNotContains(IndexEntryNote::ID, $this->history());
	}

	public function test_precision_and_default_change_together_emit_one_statement(): void {
		$this->migrator()->migrate(TrackEntryUpdates::ID);

		$steps = $this->migrator()->migrate(ReduceCreatedAtPrecision::ID);

		$last = end($steps);
		$this->assertInstanceOf(Step::class, $last);
		$this->assertCount(1, $last->sql, 'Doctrine\'s CHANGE already carries the complete definition; no supplemental MODIFY');
		$this->assertStringContainsString('`created_at` datetime(3) not null default current_timestamp(3) on update current_timestamp(3)', $this->createTableSql());
		$this->assertSame([], $this->migrator()->preview());
	}

	public function test_rollback_selects_its_target_under_the_lock(): void {
		$this->migrator()->migrate(AddEntryNote::ID);
		$name = hash('sha256', constant('DB_NAME') . "\0" . $this->historyName);
		$this->assertSame(1, (int) $this->observer->fetchOne('SELECT GET_LOCK(?, 0)', [$name]));

		try {
			$this->migrator()->rollback();
			$this->fail('Expected contention before any history read.');
		} catch (MigrationAlreadyRunning) {
			$this->assertContains(AddEntryNote::ID, $this->history());
		} finally {
			$this->observer->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
		}
	}
}
