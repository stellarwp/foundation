<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Database\Exceptions\MigrationAlreadyRunning;
use StellarWP\Foundation\Database\Migration\Exceptions\IncompatibleSchema;
use StellarWP\Foundation\Database\Migration\Exceptions\IrreversibleMigration;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Migration\ValueObjects\Step;
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
	private string $tagsName;
	private string $historyName;

	protected function configuration(): array {
		return ['database' => ['migrations_table' => $this->suffix . '_history']];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->quotedEntries = $this->privateTable($this->suffix . '_entries');
		$this->tagsName      = $this->source->prefix . $this->suffix . '_tags';
		$this->privateTable($this->suffix . '_tags');
		$this->historyName = $this->source->prefix . $this->suffix . '_history';
		$this->privateTable($this->suffix . '_history');

		$this->container->singleton(EntriesTable::class, new EntriesTable($this->suffix . '_entries'));
		$this->container->singleton(TagsTable::class, new TagsTable($this->suffix . '_tags'));
		$this->container->mergeArrayVar(DatabaseProvider::MIGRATIONS, static fn (C $c): array => [
			$c->get(ReduceCreatedAtPrecision::class),
			$c->get(TrackEntryUpdates::class),
			$c->get(WidenEntryName::class),
			$c->get(AddEntryArchivedFlag::class),
			$c->get(ReplaceNoteLookup::class),
			$c->get(IndexEntryNote::class),
			$c->get(AddEntryNote::class),
			$c->get(RecreateEntryTags::class),
			$c->get(DropEntryTags::class),
			$c->get(CreateEntryTags::class),
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
		return $this->observer->fetchFirstColumn('SELECT version FROM `' . $this->historyName . '` ORDER BY version');
	}

	private function createTableSql(): string {
		$row = $this->observer->fetchAssociative('SHOW CREATE TABLE ' . $this->quotedEntries);
		self::assertIsArray($row);

		return strtolower((string) $row['Create Table']);
	}

	public function test_rollback_honors_an_irreversible_down(): void {
		$this->migrator()->migrate(AddEntryArchivedFlag::ID);
		self::assertStringContainsString('`archived`', $this->createTableSql());

		try {
			$this->migrator()->rollback();
			self::fail('Expected the author\'s refusal to reverse.');
		} catch (IrreversibleMigration $failure) {
			self::assertStringContainsString(AddEntryArchivedFlag::ID, $failure->getMessage());
		}
		self::assertContains(AddEntryArchivedFlag::ID, $this->history(), 'The refused migration stays recorded');
		self::assertStringContainsString('`archived`', $this->createTableSql(), 'No destructive DDL was reconstructed from up()');
	}

	public function test_unrelated_foreign_key_survives_an_alteration(): void {
		$this->migrator()->migrate(CreateEntries::ID);
		$this->observer->executeStatement("ALTER TABLE {$this->quotedEntries} ADD parent_id INT NULL, ADD CONSTRAINT fk_parent FOREIGN KEY (parent_id) REFERENCES {$this->table} (id)");

		$steps = $this->migrator()->migrate(AddEntryNote::ID);

		foreach ($steps as $step) {
			self::assertStringNotContainsStringIgnoringCase('foreign key', implode("\n", $step->sql));
		}
		self::assertStringContainsString('constraint `fk_parent` foreign key', $this->createTableSql());
		self::assertStringContainsString('`note`', $this->createTableSql());
	}

	public function test_change_replaces_the_complete_column_definition_and_down_restores_it(): void {
		$this->migrator()->migrate(WidenEntryName::ID);
		$column = $this->observer->createSchemaManager()->introspectTable(trim($this->quotedEntries, '`'))->getColumn('name');
		self::assertSame(120, $column->getLength());
		self::assertFalse($column->getNotnull());
		self::assertStringContainsString("comment='imported entries'", $this->createTableSql());

		$steps = $this->migrator()->rollback();

		self::assertSame([WidenEntryName::ID], array_map(static fn (Step $step): string => $step->id, $steps));
		$column = $this->observer->createSchemaManager()->introspectTable(trim($this->quotedEntries, '`'))->getColumn('name');
		self::assertSame(50, $column->getLength());
		self::assertTrue($column->getNotnull());
		self::assertStringNotContainsString('imported entries', $this->createTableSql());
	}

	public function test_timestamp_attribute_changes_emit_sql_and_rerun_cleanly(): void {
		$this->migrator()->migrate(WidenEntryName::ID);
		self::assertStringNotContainsString('`created_at` datetime(6) not null default current_timestamp(6) on update', $this->createTableSql());

		$steps = $this->migrator()->migrate(TrackEntryUpdates::ID);

		$last = end($steps);
		self::assertInstanceOf(Step::class, $last);
		self::assertSame(TrackEntryUpdates::ID, $last->id);
		self::assertCount(1, $last->sql);
		self::assertStringContainsStringIgnoringCase('MODIFY', $last->sql[0]);
		self::assertStringContainsString('`created_at` datetime(6) not null default current_timestamp(6) on update current_timestamp(6)', $this->createTableSql());
		self::assertSame([], $this->migrator()->preview(TrackEntryUpdates::ID), 'Attributes DBAL cannot compare still produce no DDL on rerun');

		$this->migrator()->rollback();
		self::assertStringContainsString('`created_at` datetime(6) not null default current_timestamp(6),', $this->createTableSql());
	}

	public function test_undeclared_timestamp_drift_is_rejected(): void {
		$this->migrator()->migrate(AddEntryArchivedFlag::ID);
		$this->observer->executeStatement("ALTER TABLE {$this->quotedEntries} MODIFY created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)");

		try {
			$this->migrator()->migrate(WidenEntryName::ID);
			self::fail('Expected the undeclared ON UPDATE to be rejected.');
		} catch (IncompatibleSchema $failure) {
			self::assertStringContainsString('created_at', $failure->getMessage());
			self::assertStringContainsString('ON UPDATE', $failure->getMessage());
		}
		self::assertNotContains(WidenEntryName::ID, $this->history());
	}

	public function test_preview_sees_existing_tables_from_later_steps_and_creates_no_ledger(): void {
		// An interrupted earlier attempt left the tags table behind, exactly as declared.
		$this->observer->executeStatement("CREATE TABLE `{$this->tagsName}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, label VARCHAR(50) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$preview = $this->migrator()->preview();

		self::assertSame(
			[CreateEntries::ID, CreateEntryTags::ID, DropEntryTags::ID, RecreateEntryTags::ID, AddEntryNote::ID, IndexEntryNote::ID, ReplaceNoteLookup::ID, AddEntryArchivedFlag::ID, WidenEntryName::ID, TrackEntryUpdates::ID, ReduceCreatedAtPrecision::ID],
			array_map(static fn (Step $step): string => $step->id, $preview),
		);
		self::assertCount(1, $preview[0]->sql, 'Entries is created');
		self::assertSame([], $preview[1]->sql, 'Tags already matches its declaration');
		self::assertStringContainsStringIgnoringCase('DROP TABLE', $preview[2]->sql[0]);
		self::assertStringContainsStringIgnoringCase('CREATE TABLE', $preview[3]->sql[0], 'Preview remembers the simulated drop and does not reload the old table');
		self::assertFalse($this->observer->createSchemaManager()->tablesExist([$this->historyName]), 'Preview executes nothing, not even ledger creation');

		$executed = $this->migrator()->migrate();
		self::assertSame(
			array_map(static fn (Step $step): array => $step->sql, $preview),
			array_map(static fn (Step $step): array => $step->sql, $executed),
		);
	}

	public function test_interrupted_index_replacement_recovers_on_retry(): void {
		$this->migrator()->migrate(IndexEntryNote::ID);
		// A previous attempt dropped the old index and died before creating the new one.
		$this->observer->executeStatement("DROP INDEX note_lookup ON {$this->quotedEntries}");

		$steps = $this->migrator()->migrate(ReplaceNoteLookup::ID);

		$last = end($steps);
		self::assertInstanceOf(Step::class, $last);
		self::assertCount(1, $last->sql, 'Only the creation half remains');
		self::assertStringContainsString('unique key `note_lookup` (`note`)', $this->createTableSql());
		self::assertContains(ReplaceNoteLookup::ID, $this->history());
	}

	public function test_conflicting_existing_index_stops_an_addition(): void {
		$this->migrator()->migrate(AddEntryNote::ID);
		$this->observer->executeStatement("CREATE UNIQUE INDEX note_lookup ON {$this->quotedEntries} (name)");

		try {
			$this->migrator()->migrate(IndexEntryNote::ID);
			self::fail('Expected the conflicting index to be rejected.');
		} catch (IncompatibleSchema $failure) {
			self::assertStringContainsString('existing index note_lookup conflicts', $failure->getMessage());
		}
		self::assertStringContainsString('unique key `note_lookup` (`name`)', $this->createTableSql(), 'The uniqueness guarantee was not replaced');
		self::assertNotContains(IndexEntryNote::ID, $this->history());
	}

	public function test_precision_and_default_change_together_emit_one_statement(): void {
		$this->migrator()->migrate(TrackEntryUpdates::ID);

		$steps = $this->migrator()->migrate(ReduceCreatedAtPrecision::ID);

		$last = end($steps);
		self::assertInstanceOf(Step::class, $last);
		self::assertCount(1, $last->sql, 'Doctrine\'s CHANGE already carries the complete definition; no supplemental MODIFY');
		self::assertStringContainsString('`created_at` datetime(3) not null default current_timestamp(3) on update current_timestamp(3)', $this->createTableSql());
		self::assertSame([], $this->migrator()->preview());
	}

	public function test_rollback_selects_its_target_under_the_lock(): void {
		$this->migrator()->migrate(AddEntryNote::ID);
		$name = hash('sha256', constant('DB_NAME') . "\0" . $this->historyName);
		self::assertSame(1, (int) $this->observer->fetchOne('SELECT GET_LOCK(?, 0)', [$name]));

		try {
			$this->migrator()->rollback();
			self::fail('Expected contention before any history read.');
		} catch (MigrationAlreadyRunning) {
			self::assertContains(AddEntryNote::ID, $this->history());
		} finally {
			$this->observer->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
		}
	}
}
