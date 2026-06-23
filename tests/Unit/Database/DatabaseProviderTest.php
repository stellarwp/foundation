<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database;

use Adbar\Dot;
use lucatume\DI52\Container as DI52Container;
use lucatume\DI52\ContainerException;
use StellarWP\ContainerContract\ContainerInterface;
use StellarWP\Foundation\Container\ContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Database\Database;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestMigration;
use StellarWP\Foundation\Tests\TestCase;

final class DatabaseProviderTest extends TestCase
{
	public function test_it_registers_default_database_configuration(): void {
		$container = $this->newContainer();

		$container->register(DatabaseProvider::class);

		$this->assertSame([], $container->get(DatabaseProvider::MIGRATIONS));
		$this->assertSame('foundation', $container->get(DatabaseProvider::COMMAND_PREFIX));
		$this->assertSame('foundation-database-migrations', $container->get(DatabaseProvider::LOCK_NAME));
		$this->assertSame(300, $container->get(DatabaseProvider::LOCK_TTL));
	}

	public function test_it_registers_configured_database_configuration(): void {
		$container = $this->newContainer([
			'database' => [
				'migrations_table' => 'custom_migrations',
				'locks_table'      => 'custom_locks',
				'command_prefix'   => 'custom',
				'lock_name'        => 'custom-migrations',
				'lock_ttl'         => '120',
			],
		]);

		$container->register(DatabaseProvider::class);

		$this->assertSame('custom_migrations', $container->get(DatabaseProvider::MIGRATIONS_TABLE));
		$this->assertSame('custom_locks', $container->get(DatabaseProvider::LOCKS_TABLE));
		$this->assertSame('custom', $container->get(DatabaseProvider::COMMAND_PREFIX));
		$this->assertSame('custom-migrations', $container->get(DatabaseProvider::LOCK_NAME));
		$this->assertSame(120, $container->get(DatabaseProvider::LOCK_TTL));
	}

	public function test_it_does_not_overwrite_preconfigured_migrations(): void {
		$migration = new TestMigration('2026_06_23_000001_create_example');
		$container = $this->newContainer();
		$container->singleton(DatabaseProvider::MIGRATIONS, [$migration]);

		$container->register(DatabaseProvider::class);

		$this->assertSame([$migration], $container->get(DatabaseProvider::MIGRATIONS));
	}

	public function test_it_fails_clearly_when_wordpress_database_is_not_available(): void {
		$previous = $GLOBALS['wpdb'] ?? null;
		unset($GLOBALS['wpdb']);

		$container = $this->newContainer();
		$container->register(DatabaseProvider::class);

		$this->expectException(ContainerException::class);
		$this->expectExceptionMessage('the global wpdb instance is not available.');

		try {
			$container->get(Database::class);
		} finally {
			if ($previous !== null) {
				$GLOBALS['wpdb'] = $previous;
			}
		}
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function newContainer(array $config = []): Container {
		$container = new ContainerAdapter(new DI52Container());
		$container->bind(Container::class, $container);
		$container->bind(ContainerInterface::class, $container);
		$container->singleton(Dot::class, new Dot($config));

		return $container;
	}
}
