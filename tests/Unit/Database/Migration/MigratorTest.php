<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration;

use DateTimeImmutable;
use StellarWP\Foundation\Database\Migration\Collection;
use StellarWP\Foundation\Database\Migration\Exceptions\MigrationLockFailed;
use StellarWP\Foundation\Database\Migration\Exceptions\UninitializedStore;
use StellarWP\Foundation\Database\Migration\Factories\LeaseFactory;
use StellarWP\Foundation\Database\Migration\Factories\SessionFactory;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Migration\Store;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\InMemoryLock;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Lock\SystemClock;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\InMemoryRepository;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchema;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestDatabaseScope;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestMigration;
use StellarWP\Foundation\Tests\Support\Fixtures\Lock\MutableClock;
use StellarWP\Foundation\Tests\TestCase;

final class MigratorTest extends TestCase
{
	public function test_it_runs_configured_migrations_against_initialized_storage(): void {
		[$migrator, $repository, $schema] = $this->newMigrator();

		$result = $migrator->run();

		$this->assertSame(['2026_06_23_000001_create_example'], $result->ran);
		$this->assertTrue($repository->hasRun('2026_06_23_000001_create_example'));
		$this->assertSame([
			'up:2026_06_23_000001_create_example',
		], $schema->statements);
	}

	public function test_it_rolls_back_configured_migrations_against_initialized_storage(): void {
		[$migrator, $repository, $schema] = $this->newMigrator();

		$migrator->run();
		$schema->statements = [];

		$result = $migrator->rollback();

		$this->assertSame(['2026_06_23_000001_create_example'], $result->rolledBack);
		$this->assertFalse($repository->hasRun('2026_06_23_000001_create_example'));
		$this->assertSame([
			'down:2026_06_23_000001_create_example',
		], $schema->statements);
	}

	public function test_it_refreshes_configured_migrations_against_initialized_storage(): void {
		[$migrator, $repository, $schema] = $this->newMigrator();

		$migrator->run();
		$schema->statements = [];

		$result = $migrator->refresh();

		$this->assertSame(['2026_06_23_000001_create_example'], $result->rolledBack);
		$this->assertSame(['2026_06_23_000001_create_example'], $result->ran);
		$this->assertTrue($repository->hasRun('2026_06_23_000001_create_example'));
		$this->assertSame([
			'down:2026_06_23_000001_create_example',
			'up:2026_06_23_000001_create_example',
		], $schema->statements);
	}

	public function test_it_exposes_migration_status_for_configured_migrations(): void {
		[$migrator, , $schema] = $this->newMigrator();

		$this->assertTrue($migrator->status()[0]->isPending());
		$this->assertSame([], $schema->statements);

		$migrator->run();

		$this->assertTrue($migrator->status()[0]->isApplied());
	}

	public function test_it_initializes_and_drops_the_migration_store(): void {
		[$migrator, , $schema] = $this->newMigrator(initialize: false);

		$this->assertFalse($migrator->isInitialized());

		$migrator->initialize();

		$this->assertTrue($migrator->isInitialized());

		$migrator->dropStore();

		$this->assertFalse($migrator->isInitialized());
		$this->assertContains('drop:nx_foundation_migrations', $schema->statements);
		$this->assertNotContains('drop:nx_foundation_locks', $schema->statements);
		$this->assertTrue($schema->tables['nx_foundation_locks']);
	}

	public function test_store_initialization_and_drop_do_not_refresh_the_lock(): void {
		$token = new LockToken(
			'nx-foundation-database-migrations',
			'owner',
			new DateTimeImmutable('+5 minutes')
		);
		$lock = $this->createMock(Lock::class);
		$lock->expects($this->exactly(2))
			->method('acquire')
			->willReturn($token);
		$lock->expects($this->never())
			->method('refresh');
		$lock->expects($this->exactly(2))
			->method('release')
			->with($token)
			->willReturn(true);

		[$migrator] = $this->newMigrator($lock);

		$migrator->dropStore();
	}

	public function test_it_does_not_drop_the_store_while_another_migration_owns_the_lock(): void {
		$lock                  = new InMemoryLock(new MutableClock(new DateTimeImmutable('2026-01-01 00:00:00')));
		[$migrator, , $schema] = $this->newMigrator($lock);

		$token = $lock->acquire('nx-foundation-database-migrations', 300);

		$this->assertNotNull($token);
		$this->expectException(MigrationLockFailed::class);

		try {
			$migrator->dropStore();
		} finally {
			$this->assertTrue($schema->tables['nx_foundation_migrations']);
		}
	}

	public function test_it_does_not_initialize_the_ledger_while_another_migration_owns_the_lock(): void {
		$lock                  = new InMemoryLock(new MutableClock(new DateTimeImmutable('2026-01-01 00:00:00')));
		[$migrator, , $schema] = $this->newMigrator($lock, false);
		$token                 = $lock->acquire('nx-foundation-database-migrations', 300);

		$this->assertNotNull($token);
		$this->expectException(MigrationLockFailed::class);

		try {
			$migrator->initialize();
		} finally {
			$this->assertTrue($schema->tables['nx_foundation_locks']);
			$this->assertArrayNotHasKey('nx_foundation_migrations', $schema->tables);
		}
	}

	public function test_status_uses_the_existing_ledger_when_shared_lock_storage_is_missing(): void {
		[$migrator, , $schema] = $this->newMigrator();

		$migrator->run();
		unset($schema->tables['nx_foundation_locks']);

		$this->assertFalse($migrator->isInitialized());
		$this->assertTrue($migrator->status()[0]->isApplied());
	}

	public function test_it_rejects_migration_operations_before_storage_is_initialized(): void {
		[$migrator, , $schema] = $this->newMigrator(initialize: false);

		$this->expectException(UninitializedStore::class);

		try {
			$migrator->run();
		} finally {
			$this->assertSame([], $schema->statements);
		}
	}

	public function test_it_does_not_acquire_the_migration_lock_without_lock_storage(): void {
		$lock = $this->createMock(Lock::class);
		$lock->expects($this->never())->method('acquire');

		[$migrator, , $schema]                      = $this->newMigrator($lock, initialize: false);
		$schema->tables['nx_foundation_migrations'] = true;

		$this->expectException(UninitializedStore::class);

		$migrator->run();
	}

	public function test_it_rechecks_storage_after_acquiring_the_migration_lock(): void {
		$schema         = new RecordingSchema();
		$scope          = new TestDatabaseScope();
		$database       = new FakeDatabase();
		$migrationTable = new MigrationTable('nx_foundation_migrations', $database);
		$lockTable      = new LockTable('nx_foundation_locks', $database);
		$token          = new LockToken(
			'nx-foundation-database-migrations',
			'owner',
			new DateTimeImmutable('+5 minutes')
		);
		$lock = $this->createMock(Lock::class);

		$schema->tables[$migrationTable->unprefixedName()] = true;
		$schema->tables[$lockTable->unprefixedName()]      = true;

		$lock->expects($this->once())
			->method('acquire')
			->willReturnCallback(static function () use ($schema, $migrationTable, $token): LockToken {
				unset($schema->tables[$migrationTable->unprefixedName()]);

				return $token;
			});
		$lock->expects($this->once())
			->method('release')
			->with($token)
			->willReturn(true);

		$migrator = new Migrator(
			new Collection([new TestMigration('2026_06_23_000001_create_example')]),
			new InMemoryRepository(),
			new Store($schema, new LeaseFactory(), new SessionFactory(), $scope, $lock, $migrationTable, $lockTable, 'nx-foundation-database-migrations', 300)
		);

		$this->expectException(UninitializedStore::class);

		$migrator->run();
	}

	/**
	 * @return array{Migrator, InMemoryRepository, RecordingSchema}
	 */
	private function newMigrator(?Lock $lock = null, bool $initialize = true): array {
		$lock ??= new InMemoryLock(new SystemClock());

		$schema         = new RecordingSchema();
		$repository     = new InMemoryRepository();
		$scope          = new TestDatabaseScope();
		$database       = new FakeDatabase();
		$migrationTable = new MigrationTable('nx_foundation_migrations', $database);
		$lockTable      = new LockTable('nx_foundation_locks', $database);
		$store          = new Store($schema, new LeaseFactory(), new SessionFactory(), $scope, $lock, $migrationTable, $lockTable, 'nx-foundation-database-migrations', 300);

		$migrator = new Migrator(
			new Collection([
				new TestMigration('2026_06_23_000001_create_example'),
			]),
			$repository,
			$store
		);

		if ($initialize) {
			$migrator->initialize();
			$schema->statements = [];
		}

		return [
			$migrator,
			$repository,
			$schema,
		];
	}
}
