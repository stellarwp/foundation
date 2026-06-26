<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Cli;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use StellarWP\Foundation\Database\Cli\Migrate;
use StellarWP\Foundation\Database\Migration\Collection as MigrationCollection;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Migration\Runner;
use StellarWP\Foundation\Database\Migration\Store;
use StellarWP\Foundation\Database\Schema as DatabaseSchema;
use StellarWP\Foundation\Database\Table\Collection as TableCollection;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Lock\InMemoryLock;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\InMemoryRepository;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchema;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestMigration;
use StellarWP\Foundation\Tests\TestCase;
use WP_CLI;

final class MigrateTest extends TestCase
{
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_registers_the_migration_command_with_wp_cli(): void {
		if (! defined('WP_CLI')) {
			define('WP_CLI', true);
		}

		$this->loadWpCliUtilities();

		$database = new FakeDatabase();
		$wpSchema = new DatabaseSchema($database, static fn (string $sql): array => []);
		$command  = new Migrate(
			$this->container,
			'foundation',
			new Migrator(
				new Store(new TableCollection($wpSchema, [
					new MigrationTable($database, 'wp_nexcess_foundation_migrations'),
					new LockTable($database, 'wp_nexcess_foundation_locks'),
				])),
				new Runner(new InMemoryRepository(), new RecordingSchema(), new InMemoryLock()),
				new MigrationCollection()
			)
		);

		$command->register();

		$deferredAdditions = WP_CLI::get_deferred_additions();

		$this->assertArrayHasKey('foundation migrate', $deferredAdditions);
		$this->assertSame('foundation', $deferredAdditions['foundation migrate']['parent']);
		$this->assertSame('List and manage database migrations.', $deferredAdditions['foundation migrate']['args']['shortdesc']);
		$this->assertSame([
			[
				'type'        => 'flag',
				'name'        => 'run',
				'description' => 'Run pending migrations.',
				'optional'    => true,
				'default'     => false,
			],
			[
				'type'        => 'flag',
				'name'        => 'rollback',
				'description' => 'Rollback the latest migration batch.',
				'optional'    => true,
				'default'     => false,
			],
			[
				'type'        => 'flag',
				'name'        => 'refresh',
				'description' => 'Rollback and rerun all migrations.',
				'optional'    => true,
				'default'     => false,
			],
			[
				'type'        => 'flag',
				'name'        => 'drop',
				'description' => 'Drop Foundation database tables.',
				'optional'    => true,
				'default'     => false,
			],
			[
				'type'        => 'flag',
				'name'        => 'prepare',
				'description' => 'Prepare Foundation migration storage without running migrations.',
				'optional'    => true,
				'default'     => false,
			],
			[
				'type'        => 'flag',
				'name'        => 'create-table',
				'description' => 'Alias for --prepare.',
				'optional'    => true,
				'default'     => false,
			],
			[
				'type'        => 'flag',
				'name'        => 'yes',
				'description' => 'Skip confirmation prompts for destructive actions.',
				'optional'    => true,
				'default'     => false,
			],
		], $deferredAdditions['foundation migrate']['args']['synopsis']);
	}

	public function test_it_creates_database_tables_without_running_migrations(): void {
		[$command, $repository, $schema] = $this->newCommand();

		$this->assertSame(0, $command->runCommand([], ['prepare' => true]));

		$this->assertSame([], $repository->all());
		$this->assertSame([
			'createOrUpdate:wp_nexcess_foundation_migrations',
			'createOrUpdate:wp_nexcess_foundation_locks',
		], $schema->statements);
	}

	public function test_it_supports_create_table_as_an_alias_for_prepare(): void {
		[$command, $repository, $schema] = $this->newCommand();

		$this->assertSame(0, $command->runCommand([], ['create-table' => true]));

		$this->assertSame([], $repository->all());
		$this->assertSame([
			'createOrUpdate:wp_nexcess_foundation_migrations',
			'createOrUpdate:wp_nexcess_foundation_locks',
		], $schema->statements);
	}

	public function test_it_rejects_conflicting_migration_operations(): void {
		[$command, $repository, $schema] = $this->newCommand();

		$this->assertSame(1, $command->runCommand([], [
			'run'     => true,
			'prepare' => true,
		]));

		$this->assertSame([], $repository->all());
		$this->assertSame([], $schema->statements);
	}

	public function test_it_runs_pending_migrations(): void {
		[$command, $repository, $schema] = $this->newCommand();

		$this->assertSame(0, $command->runCommand([], ['run' => true]));

		$this->assertTrue($repository->hasRun('2026_06_23_000001_create_example'));
		$this->assertSame([
			'createOrUpdate:wp_nexcess_foundation_migrations',
			'createOrUpdate:wp_nexcess_foundation_locks',
			'up:2026_06_23_000001_create_example',
		], $schema->statements);
	}

	public function test_it_rolls_back_the_latest_migration_batch(): void {
		[$command, $repository, $schema] = $this->newCommand();

		$command->runCommand([], ['run' => true]);
		$schema->statements = [];

		$this->assertSame(0, $command->runCommand([], ['rollback' => true]));

		$this->assertFalse($repository->hasRun('2026_06_23_000001_create_example'));
		$this->assertContains('down:2026_06_23_000001_create_example', $schema->statements);
	}

	public function test_it_refreshes_database_migrations(): void {
		[$command, $repository, $schema] = $this->newCommand();

		$command->runCommand([], ['run' => true]);
		$schema->statements = [];

		$this->assertSame(0, $command->runCommand([], [
			'refresh' => true,
			'yes'     => true,
		]));

		$this->assertTrue($repository->hasRun('2026_06_23_000001_create_example'));
		$this->assertContains('down:2026_06_23_000001_create_example', $schema->statements);
		$this->assertContains('up:2026_06_23_000001_create_example', $schema->statements);
	}

	public function test_it_drops_database_tables(): void {
		[$command, , $schema] = $this->newCommand();

		$command->runCommand([], ['create-table' => true]);

		$this->assertSame(0, $command->runCommand([], [
			'drop' => true,
			'yes'  => true,
		]));

		$this->assertSame([], $schema->tables);
		$this->assertContains('drop:wp_nexcess_foundation_migrations', $schema->statements);
		$this->assertContains('drop:wp_nexcess_foundation_locks', $schema->statements);
	}

	public function test_it_shows_a_warning_when_status_tables_do_not_exist(): void {
		[$command] = $this->newCommand();

		$this->expectOutputRegex('/2026_06_23_000001_create_example\s+pending/');

		$this->assertSame(0, $command->runCommand());
	}

	public function test_it_shows_migration_status_when_tables_exist(): void {
		[$command] = $this->newCommand();

		$command->runCommand([], ['run' => true]);

		$this->expectOutputRegex('/2026_06_23_000001_create_example\s+ran\s+1\s+2026-01-01 00:00:00/');

		$this->assertSame(0, $command->runCommand());
	}

	/**
	 * @return array{Migrate, InMemoryRepository, RecordingSchema}
	 */
	private function newCommand(): array {
		$this->loadWpCliUtilities();

		$database   = new FakeDatabase();
		$wpSchema   = new RecordingSchema();
		$repository = new InMemoryRepository();
		$runner     = new Runner($repository, $wpSchema, new InMemoryLock());
		$command    = new Migrate(
			$this->container,
			'foundation',
			new Migrator(
				new Store(new TableCollection($wpSchema, [
					new MigrationTable($database, 'wp_nexcess_foundation_migrations'),
					new LockTable($database, 'wp_nexcess_foundation_locks'),
				])),
				$runner,
				new MigrationCollection([
					new TestMigration('2026_06_23_000001_create_example'),
				])
			)
		);

		return [$command, $repository, $wpSchema];
	}

	private function loadWpCliUtilities(): void {
		$wpCliRoot = dirname(__DIR__, 4) . '/vendor/wp-cli/wp-cli';

		if (! defined('WP_CLI_ROOT')) {
			define('WP_CLI_ROOT', $wpCliRoot);
		}

		require_once $wpCliRoot . '/php/utils.php';
	}
}
