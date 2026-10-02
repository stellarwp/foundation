<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\LockDatabase;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\Contracts\Configuration;
use StellarWP\Foundation\Database\Contracts\DatabaseScope;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Database\Table\TableNameResolver as DatabaseTableNameResolver;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\InMemoryLock;
use StellarWP\Foundation\Lock\SystemClock;
use StellarWP\Foundation\LockDatabase\DatabaseLock;
use StellarWP\Foundation\LockDatabase\LockDatabaseProvider;
use StellarWP\Foundation\LockDatabase\Tables\LockTable;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;

final class LockDatabaseProviderTest extends WPTestCase
{
	/**
	 * @return iterable<string, array{array<string, mixed>, string}>
	 */
	public static function tableNames(): iterable {
		yield 'default' => [[], 'wp_nx_foundation_locks'];

		yield 'application prefix' => [['foundation' => ['prefix' => 'your-plugin']], 'wp_your_plugin_foundation_locks'];

		yield 'explicit override' => [[
			'foundation' => ['prefix' => 'your-plugin'],
			'lock'       => ['database' => ['table' => 'Custom_Locks']],
		], 'wp_Custom_Locks'];
	}

	/**
	 * @dataProvider tableNames
	 *
	 * @param array<string, mixed> $configuration
	 */
	#[DataProvider('tableNames')]
	public function test_it_uses_the_shared_database_services_and_configured_storage(array $configuration, string $name): void {
		$this->container->register(DatabaseProvider::class);
		$db = $this->createMock(Connection::class);
		$db->expects($this->never())->method('executeStatement');
		$scope = $this->createMock(DatabaseScope::class);
		$scope->method('resolveTableName')->willReturnCallback(static fn (string $suffix): string => 'wp_' . $suffix);
		$this->container->singleton(Configuration::class, new ArrayConfiguration($configuration));
		$this->container->singleton(Connection::class, $db);
		$this->container->singleton(TableNameResolver::class, new DatabaseTableNameResolver($scope));
		$this->container->register(LockDatabaseProvider::class);

		$this->assertSame($name, $this->container->get(LockTable::class)->name());
		$lock = $this->container->get(DatabaseLock::class);
		$this->assertSame($lock, $this->container->get(DatabaseLock::class));
		$this->assertSame($db, $this->container->get(Connection::class));
		$this->assertFalse($this->container->has(Lock::class));
	}

	public function test_registration_preserves_an_existing_application_lock_without_resolving_database_services(): void {
		$existing = new InMemoryLock(new SystemClock());
		$this->container->singleton(Lock::class, $existing);
		$this->container->register(LockDatabaseProvider::class);

		$this->assertSame($existing, $this->container->get(Lock::class));
		$this->assertNotNull($existing->acquire('resource', 60));
	}
}
