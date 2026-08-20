<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration;

use StellarWP\Foundation\Database\Exceptions\MigrationLockFailed;
use StellarWP\Foundation\Database\Migration\Collection;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Migration\Store;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Lock\InMemoryLock;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\InMemoryRepository;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchema;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestMigration;
use StellarWP\Foundation\Tests\TestCase;

final class MigratorTest extends TestCase
{
	public function test_it_prepares_the_store_before_running_configured_migrations(): void {
		[$migrator, $repository, $schema] = $this->newMigrator();

		$result = $migrator->run();

		$this->assertSame(['2026_06_23_000001_create_example'], $result->ran);
		$this->assertTrue($repository->hasRun('2026_06_23_000001_create_example'));
		$this->assertSame([
			'createOrUpdate:wp_nexcess_foundation_locks',
			'createOrUpdate:wp_nexcess_foundation_migrations',
			'up:2026_06_23_000001_create_example',
		], $schema->statements);
	}

	public function test_it_prepares_the_store_before_rolling_back_configured_migrations(): void {
		[$migrator, $repository, $schema] = $this->newMigrator();

		$migrator->run();
		$schema->statements = [];

		$result = $migrator->rollback();

		$this->assertSame(['2026_06_23_000001_create_example'], $result->rolledBack);
		$this->assertFalse($repository->hasRun('2026_06_23_000001_create_example'));
		$this->assertSame([
			'createOrUpdate:wp_nexcess_foundation_locks',
			'createOrUpdate:wp_nexcess_foundation_migrations',
			'down:2026_06_23_000001_create_example',
		], $schema->statements);
	}

	public function test_it_prepares_the_store_before_refreshing_configured_migrations(): void {
		[$migrator, $repository, $schema] = $this->newMigrator();

		$migrator->run();
		$schema->statements = [];

		$result = $migrator->refresh();

		$this->assertSame(['2026_06_23_000001_create_example'], $result->rolledBack);
		$this->assertSame(['2026_06_23_000001_create_example'], $result->ran);
		$this->assertTrue($repository->hasRun('2026_06_23_000001_create_example'));
		$this->assertSame([
			'createOrUpdate:wp_nexcess_foundation_locks',
			'createOrUpdate:wp_nexcess_foundation_migrations',
			'down:2026_06_23_000001_create_example',
			'up:2026_06_23_000001_create_example',
		], $schema->statements);
	}

	public function test_it_exposes_migration_status_for_configured_migrations(): void {
		[$migrator, , $schema] = $this->newMigrator();

		$this->assertFalse($migrator->status()[0]->ran);
		$this->assertSame([], $schema->statements);

		$migrator->run();

		$this->assertTrue($migrator->status()[0]->ran);
	}

	public function test_it_prepares_and_drops_the_migration_store(): void {
		[$migrator, , $schema] = $this->newMigrator();

		$this->assertFalse($migrator->exists());

		$migrator->prepare();

		$this->assertTrue($migrator->exists());

		$migrator->dropStore();

		$this->assertFalse($migrator->exists());
		$this->assertContains('drop:wp_nexcess_foundation_migrations', $schema->statements);
		$this->assertNotContains('drop:wp_nexcess_foundation_locks', $schema->statements);
		$this->assertTrue($schema->tables['wp_nexcess_foundation_locks']);
	}

	public function test_it_does_not_drop_the_store_while_another_migration_owns_the_lock(): void {
		$lock                  = new InMemoryLock();
		[$migrator, , $schema] = $this->newMigrator($lock);

		$migrator->prepare();
		$token = $lock->acquire('foundation-database-migrations', 300);

		$this->assertNotNull($token);
		$this->expectException(MigrationLockFailed::class);

		try {
			$migrator->dropStore();
		} finally {
			$this->assertTrue($schema->tables['wp_nexcess_foundation_migrations']);
		}
	}

	public function test_it_does_not_prepare_the_ledger_while_another_migration_owns_the_lock(): void {
		$lock                  = new InMemoryLock();
		[$migrator, , $schema] = $this->newMigrator($lock);
		$token                 = $lock->acquire('foundation-database-migrations', 300);

		$this->assertNotNull($token);
		$this->expectException(MigrationLockFailed::class);

		try {
			$migrator->prepare();
		} finally {
			$this->assertTrue($schema->tables['wp_nexcess_foundation_locks']);
			$this->assertArrayNotHasKey('wp_nexcess_foundation_migrations', $schema->tables);
		}
	}

	public function test_status_uses_the_existing_ledger_when_shared_lock_storage_is_missing(): void {
		[$migrator, , $schema] = $this->newMigrator();

		$migrator->run();
		unset($schema->tables['wp_nexcess_foundation_locks']);

		$this->assertFalse($migrator->exists());
		$this->assertTrue($migrator->status()[0]->ran);
	}

	/**
	 * @return array{Migrator, InMemoryRepository, RecordingSchema}
	 */
	private function newMigrator(?InMemoryLock $lock = null): array {
		$schema     = new RecordingSchema();
		$repository = new InMemoryRepository();
		$lock ??= new InMemoryLock();
		$migrationTable = new MigrationTable('wp_nexcess_foundation_migrations');
		$lockTable      = new LockTable('wp_nexcess_foundation_locks');
		$store          = new Store($migrationTable, $lockTable);

		return [
			new Migrator(
				new Collection([
					new TestMigration('2026_06_23_000001_create_example'),
				]),
				$repository,
				$schema,
				$lock,
				$store
			),
			$repository,
			$schema,
		];
	}
}
