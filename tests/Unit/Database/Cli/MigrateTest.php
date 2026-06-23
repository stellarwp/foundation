<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Cli;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use StellarWP\Foundation\Database\Cli\Migrate;
use StellarWP\Foundation\Database\Migration\Runner;
use StellarWP\Foundation\Database\Schema as DatabaseSchema;
use StellarWP\Foundation\Database\Table\Collection as TableCollection;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Lock\InMemoryLock;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\InMemoryRepository;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchema;
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

		$wpCliRoot = dirname(__DIR__, 4) . '/vendor/wp-cli/wp-cli';

		if (! defined('WP_CLI_ROOT')) {
			define('WP_CLI_ROOT', $wpCliRoot);
		}

		require_once $wpCliRoot . '/php/utils.php';

		$database = new FakeDatabase();
		$wpSchema = new DatabaseSchema($database, static fn (string $sql): array => []);
		$command  = new Migrate(
			$this->container,
			'foundation',
			new Runner(new InMemoryRepository(), new RecordingSchema(), new InMemoryLock()),
			[],
			new TableCollection($wpSchema, [
				new MigrationTable($database, 'wp_nexcess_foundation_migrations'),
				new LockTable($database, 'wp_nexcess_foundation_locks'),
			])
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
				'name'        => 'create-table',
				'description' => 'Create Foundation database tables without running migrations.',
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
}
