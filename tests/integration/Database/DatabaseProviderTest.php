<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use Adbar\Dot;
use InvalidArgumentException;
use lucatume\DI52\Container as DI52Container;
use lucatume\DI52\ContainerException;
use StellarWP\ContainerContract\ContainerInterface;
use StellarWP\Foundation\Container\ContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Database\Cli\Migrate;
use StellarWP\Foundation\Database\Contracts\DatabaseScope;
use StellarWP\Foundation\Database\Database;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Database\Lock\DatabaseLock;
use StellarWP\Foundation\Database\Migration\Collection;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Scope\SiteScope;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\NoopMigration;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestMigration;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;
use StellarWP\Foundation\WPCli\Command;
use StellarWP\Foundation\WPCli\ValueObjects\CommandPrefix;
use StellarWP\Foundation\WPCli\WPCliProvider;

final class DatabaseProviderTest extends WPTestCase
{
	public function test_it_registers_default_database_configuration(): void {
		$this->container->register(WPCliProvider::class);
		$this->container->register(DatabaseProvider::class);

		$commands = $this->container->get(WPCliProvider::COMMANDS);

		$this->assertSame([], $this->container->get(DatabaseProvider::MIGRATIONS));
		$this->assertSame('nx-foundation-database-migrations', $this->container->get(DatabaseProvider::LOCK_NAME));
		$this->assertSame(300, $this->container->get(DatabaseProvider::LOCK_TTL));
		$this->assertContainsOnlyInstancesOf(Command::class, $commands);
		$this->assertTrue($this->containsMigrateCommand((array) $commands));
		$this->assertInstanceOf(DatabaseLock::class, $this->container->get(DatabaseLock::class));
		$this->assertInstanceOf(SiteScope::class, $this->container->get(DatabaseScope::class));
		$this->assertInstanceOf(Migrator::class, $this->container->get(Migrator::class));
		$this->assertInstanceOf(Migrate::class, $this->container->get(Migrate::class));
	}

	public function test_it_registers_configured_database_configuration(): void {
		$container = $this->newContainer([
			'foundation' => [
				'prefix' => 'your-plugin',
			],
			'database'   => [
				'migrations_table' => 'custom_migrations',
				'locks_table'      => 'custom_locks',
				'lock_name'        => 'custom-migrations',
				'lock_ttl'         => '120',
			],
			'wpcli'      => [
				'command_prefix' => 'custom',
			],
		]);

		$container->register(WPCliProvider::class);
		$container->register(DatabaseProvider::class);

		$this->assertSame('custom_migrations', $container->get(DatabaseProvider::MIGRATIONS_TABLE));
		$this->assertSame('custom_locks', $container->get(DatabaseProvider::LOCKS_TABLE));
		$this->assertSame($GLOBALS['wpdb']->prefix . 'custom_migrations', $container->get(MigrationTable::class)->name());
		$this->assertSame($GLOBALS['wpdb']->prefix . 'custom_locks', $container->get(LockTable::class)->name());
		$this->assertSame('custom-migrations', $container->get(DatabaseProvider::LOCK_NAME));
		$this->assertSame(120, $container->get(DatabaseProvider::LOCK_TTL));
		$this->assertSame('custom', $container->get(CommandPrefix::class)->value);
	}

	public function test_it_rejects_an_invalid_foundation_prefix_when_database_resources_are_overridden(): void {
		$container = $this->newContainer([
			'foundation' => [
				'prefix' => 'Invalid Prefix',
			],
			'database'   => [
				'migrations_table' => 'custom_migrations',
				'locks_table'      => 'custom_locks',
				'lock_name'        => 'custom-migrations',
			],
		]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('lowercase kebab-case');

		$container->register(DatabaseProvider::class);
	}

	public function test_it_scopes_default_resources_with_the_foundation_prefix(): void {
		$container = $this->newContainer([
			'foundation' => [
				'prefix' => 'your-plugin',
			],
		]);

		$container->register(WPCliProvider::class);
		$container->register(DatabaseProvider::class);

		$this->assertSame('your_plugin_foundation_migrations', $container->get(DatabaseProvider::MIGRATIONS_TABLE));
		$this->assertSame('your_plugin_foundation_locks', $container->get(DatabaseProvider::LOCKS_TABLE));
		$this->assertSame($GLOBALS['wpdb']->prefix . 'your_plugin_foundation_migrations', $container->get(MigrationTable::class)->name());
		$this->assertSame($GLOBALS['wpdb']->prefix . 'your_plugin_foundation_locks', $container->get(LockTable::class)->name());
		$this->assertSame('your-plugin-foundation-database-migrations', $container->get(DatabaseProvider::LOCK_NAME));
		$this->assertSame('your-plugin', $container->get(CommandPrefix::class)->value);
	}

	public function test_migrator_reuses_the_same_services_across_complete_site_operations(): void {
		$suffix          = str_replace('.', '_', uniqid('', true));
		$migrationsTable = 'foundation_multisite_migrations_' . $suffix;
		$locksTable      = 'foundation_multisite_locks_' . $suffix;
		$migration       = new NoopMigration('2026_08_24_000001_multisite_migration');
		$container       = $this->newContainer([
			'database' => [
				'migrations_table' => $migrationsTable,
				'locks_table'      => $locksTable,
			],
		]);
		$container->mergeArrayVar(DatabaseProvider::MIGRATIONS, [$migration]);
		$container->register(WPCliProvider::class);
		$container->register(DatabaseProvider::class);

		$migrator       = $container->get(Migrator::class);
		$database       = $container->get(Database::class);
		$migrationTable = $container->get(MigrationTable::class);
		$lockTable      = $container->get(LockTable::class);
		$mainSiteId     = get_current_blog_id();
		$mainTables     = [$migrationTable->name(), $lockTable->name()];
		$siteId         = 0;
		$siteTables     = [];

		try {
			$migrator->initialize();
			$this->assertSame([$migration->id()], $migrator->run()->ran);

			$createdSite = $this->factory()->blog->create();

			if ($createdSite instanceof \WP_Error) {
				$this->fail($createdSite->get_error_message());
			}

			$siteId = $createdSite;
			switch_to_blog($siteId);
			$siteTables = [$migrationTable->name(), $lockTable->name()];

			$this->assertNotSame($mainTables, $siteTables);
			$migrator->initialize();
			$this->assertSame([$migration->id()], $migrator->run()->ran);

			restore_current_blog();
			$this->assertSame($mainSiteId, get_current_blog_id());
			$this->assertSame($mainTables, [$migrationTable->name(), $lockTable->name()]);
			$this->assertSame([$migration->id()], $migrator->run()->skipped);
		} finally {
			if (get_current_blog_id() !== $mainSiteId) {
				restore_current_blog();
			}

			foreach ($mainTables as $table) {
				$database->execute('DROP TABLE IF EXISTS %i', $table);
			}

			if ($siteId !== 0) {
				switch_to_blog($siteId);

				foreach ($siteTables as $table) {
					$database->execute('DROP TABLE IF EXISTS %i', $table);
				}

				restore_current_blog();
				wp_delete_site($siteId);
			}
		}
	}

	public function test_it_applies_configured_lock_policy_to_the_migration_store(): void {
		$configurations = [
			[['database' => ['lock_name' => '   ']], 'lock name cannot be empty'],
			[['database' => ['lock_ttl' => 0]], 'TTL must be at least one second'],
		];

		foreach ($configurations as [$config, $message]) {
			$container = $this->newContainer($config);
			$container->register(WPCliProvider::class);
			$container->register(DatabaseProvider::class);

			try {
				$container->get(Migrator::class);
				$this->fail('Expected invalid migration lock configuration to be rejected.');
			} catch (ContainerException $exception) {
				$this->assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
				$this->assertStringContainsString($message, $exception->getMessage());
			}
		}
	}

	public function test_it_preserves_preconfigured_migrations(): void {
		$migration = new TestMigration('2026_06_23_000001_create_example');
		$container = $this->newContainer();
		$container->mergeArrayVar(DatabaseProvider::MIGRATIONS, [$migration]);

		$container->register(WPCliProvider::class);
		$container->register(DatabaseProvider::class);

		$this->assertSame([$migration], $container->get(DatabaseProvider::MIGRATIONS));
		$this->assertSame([$migration->id() => $migration], $container->get(Collection::class)->all());
		$this->assertSame([$migration], $container->get(Collection::class)->values());
	}

	public function test_it_collects_migrations_added_after_provider_registration(): void {
		$migration = new TestMigration('2026_06_23_000001_create_example');
		$container = $this->newContainer();

		$container->register(WPCliProvider::class);
		$container->register(DatabaseProvider::class);
		$container->mergeArrayVar(DatabaseProvider::MIGRATIONS, [$migration]);

		$this->assertSame([$migration->id() => $migration], $container->get(Collection::class)->all());
		$this->assertSame([$migration], $container->get(Collection::class)->values());
	}

	public function test_provider_built_migrator_executes_against_wordpress(): void {
		$suffix              = str_replace('.', '_', uniqid('', true));
		$migrationsTableName = 'foundation_provider_migrations_' . $suffix;
		$locksTableName      = 'foundation_provider_locks_' . $suffix;
		$migrationsTable     = $GLOBALS['wpdb']->prefix . $migrationsTableName;
		$locksTable          = $GLOBALS['wpdb']->prefix . $locksTableName;
		$migration           = new NoopMigration('2026_08_20_000001_provider_migration');
		$container           = $this->newContainer([
			'database' => [
				'migrations_table' => $migrationsTableName,
				'locks_table'      => $locksTableName,
			],
		]);
		$container->mergeArrayVar(DatabaseProvider::MIGRATIONS, [$migration]);
		$container->register(WPCliProvider::class);
		$container->register(DatabaseProvider::class);

		$database             = $container->get(Database::class);
		$migrationTableObject = $container->get(MigrationTable::class);
		$lockTableObject      = $container->get(LockTable::class);

		try {
			$migrator = $container->get(Migrator::class);
			$migrator->initialize();
			$result = $migrator->run();

			$this->assertSame([$migration->id()], $result->ran);
			$this->assertTrue($database->tableExists($migrationTableObject));
			$this->assertTrue($database->tableExists($lockTableObject));
			$this->assertTrue($migrator->status()[0]->isApplied());
		} finally {
			$database->execute('DROP TABLE IF EXISTS %i', $migrationsTable);
			$database->execute('DROP TABLE IF EXISTS %i', $locksTable);
		}
	}

	/**
	 * @param array<string,mixed> $config
	 */
	private function newContainer(array $config = []): Container {
		$container = new ContainerAdapter(new DI52Container());
		$container->bind(Container::class, $container);
		$container->bind(ContainerInterface::class, $container);
		$container->singleton(Dot::class, new Dot($config));

		return $container;
	}

	/**
	 * @param array<mixed> $commands
	 */
	private function containsMigrateCommand(array $commands): bool {
		foreach ($commands as $command) {
			if ($command instanceof Migrate) {
				return true;
			}
		}

		return false;
	}
}
