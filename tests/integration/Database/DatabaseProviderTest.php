<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use Adbar\Dot;
use lucatume\DI52\Container as DI52Container;
use StellarWP\ContainerContract\ContainerInterface;
use StellarWP\Foundation\Container\ContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Database\Cli\Migrate;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestMigration;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;
use StellarWP\Foundation\WPCli\Command;
use StellarWP\Foundation\WPCli\WPCliProvider;

final class DatabaseProviderTest extends WPTestCase
{
	public function test_it_registers_default_database_configuration(): void {
		$this->container->register(WPCliProvider::class);
		$this->container->register(DatabaseProvider::class);

		$commands = $this->container->get(WPCliProvider::COMMANDS);

		$this->assertSame([], $this->container->get(DatabaseProvider::MIGRATIONS));
		$this->assertSame('foundation-database-migrations', $this->container->get(DatabaseProvider::LOCK_NAME));
		$this->assertSame(300, $this->container->get(DatabaseProvider::LOCK_TTL));
		$this->assertContainsOnlyInstancesOf(Command::class, $commands);
		$this->assertTrue($this->containsMigrateCommand((array) $commands));
	}

	public function test_it_registers_configured_database_configuration(): void {
		$container = $this->newContainer([
			'database' => [
				'migrations_table' => 'custom_migrations',
				'locks_table'      => 'custom_locks',
				'lock_name'        => 'custom-migrations',
				'lock_ttl'         => '120',
			],
			'wpcli'    => [
				'command_prefix' => 'custom',
			],
		]);

		$container->register(WPCliProvider::class);
		$container->register(DatabaseProvider::class);

		$this->assertSame('custom_migrations', $container->get(DatabaseProvider::MIGRATIONS_TABLE));
		$this->assertSame('custom_locks', $container->get(DatabaseProvider::LOCKS_TABLE));
		$this->assertSame('custom-migrations', $container->get(DatabaseProvider::LOCK_NAME));
		$this->assertSame(120, $container->get(DatabaseProvider::LOCK_TTL));
		$this->assertSame('custom', $container->get(WPCliProvider::COMMAND_PREFIX));
	}

	public function test_it_preserves_preconfigured_migrations(): void {
		$migration = new TestMigration('2026_06_23_000001_create_example');
		$container = $this->newContainer();
		$container->mergeArrayVar(DatabaseProvider::MIGRATIONS, [$migration]);

		$container->register(WPCliProvider::class);
		$container->register(DatabaseProvider::class);

		$this->assertSame([$migration], $container->get(DatabaseProvider::MIGRATIONS));
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
