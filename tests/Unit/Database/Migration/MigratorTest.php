<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration;

use StellarWP\Foundation\Database\Migration\Collection;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Migration\Runner;
use StellarWP\Foundation\Database\Migration\Store;
use StellarWP\Foundation\Database\Table\Collection as TableCollection;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Lock\InMemoryLock;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
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
			'createOrUpdate:wp_nexcess_foundation_migrations',
			'createOrUpdate:wp_nexcess_foundation_locks',
			'up:2026_06_23_000001_create_example',
		], $schema->statements);
	}

	public function test_it_prepares_the_store_before_rolling_back_configured_migrations(): void {
		[$migrator, $repository, $schema] = $this->newMigrator();

		$migrator->run();
		$migrator->drop();
		$schema->statements = [];

		$result = $migrator->rollback();

		$this->assertSame(['2026_06_23_000001_create_example'], $result->rolledBack);
		$this->assertFalse($repository->hasRun('2026_06_23_000001_create_example'));
		$this->assertSame([
			'createOrUpdate:wp_nexcess_foundation_migrations',
			'createOrUpdate:wp_nexcess_foundation_locks',
			'down:2026_06_23_000001_create_example',
		], $schema->statements);
	}

	public function test_it_prepares_the_store_before_refreshing_configured_migrations(): void {
		[$migrator, $repository, $schema] = $this->newMigrator();

		$migrator->run();
		$migrator->drop();
		$schema->statements = [];

		$result = $migrator->refresh();

		$this->assertSame(['2026_06_23_000001_create_example'], $result->rolledBack);
		$this->assertSame(['2026_06_23_000001_create_example'], $result->ran);
		$this->assertTrue($repository->hasRun('2026_06_23_000001_create_example'));
		$this->assertSame([
			'createOrUpdate:wp_nexcess_foundation_migrations',
			'createOrUpdate:wp_nexcess_foundation_locks',
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

		$migrator->drop();

		$this->assertFalse($migrator->exists());
		$this->assertContains('drop:wp_nexcess_foundation_migrations', $schema->statements);
		$this->assertContains('drop:wp_nexcess_foundation_locks', $schema->statements);
	}

	/**
	 * @return array{Migrator, InMemoryRepository, RecordingSchema}
	 */
	private function newMigrator(): array {
		$database   = new FakeDatabase();
		$schema     = new RecordingSchema();
		$repository = new InMemoryRepository();

		return [
			new Migrator(
				new Store(new TableCollection($schema, [
					new MigrationTable($database, 'wp_nexcess_foundation_migrations'),
					new LockTable($database, 'wp_nexcess_foundation_locks'),
				])),
				new Runner($repository, $schema, new InMemoryLock()),
				new Collection([
					new TestMigration('2026_06_23_000001_create_example'),
				])
			),
			$repository,
			$schema,
		];
	}
}
