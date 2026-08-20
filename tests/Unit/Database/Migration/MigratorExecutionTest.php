<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\MigrationFailed;
use StellarWP\Foundation\Database\Exceptions\MigrationLockFailed;
use StellarWP\Foundation\Database\Migration\Collection;
use StellarWP\Foundation\Database\Migration\Exceptions\UnavailableMigration;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Migration\Result;
use StellarWP\Foundation\Database\Migration\Store;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\Lock\InMemoryLock;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FailingMigration;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\InMemoryRepository;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchema;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestMigration;
use StellarWP\Foundation\Tests\Support\Fixtures\Lock\MutableClock;
use StellarWP\Foundation\Tests\TestCase;

final class MigratorExecutionTest extends TestCase
{
	private InMemoryRepository $repository;

	private RecordingSchema $schema;

	private InMemoryLock $lock;

	protected function setUp(): void {
		parent::setUp();

		$this->repository = new InMemoryRepository();
		$this->schema     = new RecordingSchema();
		$this->lock       = new InMemoryLock(new MutableClock(new \DateTimeImmutable('2026-01-01 00:00:00')));

		(new Store(
			$this->schema,
			$this->lock,
			new MigrationTable('wp_nexcess_foundation_migrations'),
			new LockTable('wp_nexcess_foundation_locks')
		))->initialize();

		$this->schema->statements = [];
	}

	public function test_it_rejects_a_blank_migration_lock_name(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('lock name cannot be empty');

		new Store(
			$this->schema,
			$this->lock,
			new MigrationTable('wp_nexcess_foundation_migrations'),
			new LockTable('wp_nexcess_foundation_locks'),
			lockName: '   '
		);
	}

	public function test_it_rejects_an_invalid_migration_lock_ttl(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('TTL must be at least one second');

		new Store(
			$this->schema,
			$this->lock,
			new MigrationTable('wp_nexcess_foundation_migrations'),
			new LockTable('wp_nexcess_foundation_locks'),
			lockTtl: 0
		);
	}

	public function test_it_runs_pending_migrations_in_order(): void {
		$result = $this->configured(
			new TestMigration('2026_01_01_000001_create_users'),
			new TestMigration('2026_01_01_000002_create_posts'),
		)->run();

		$this->assertSame([
			'2026_01_01_000001_create_users',
			'2026_01_01_000002_create_posts',
		], $result->ran);
		$this->assertSame([
			'up:2026_01_01_000001_create_users',
			'up:2026_01_01_000002_create_posts',
		], $this->schema->statements);
		$this->assertSame(1, $this->repository->all()['2026_01_01_000001_create_users']->batch);
		$this->assertSame(1, $this->repository->all()['2026_01_01_000002_create_posts']->batch);
	}

	public function test_it_skips_migrations_that_have_already_run(): void {
		$this->configured(
			new TestMigration('2026_01_01_000001_create_users'),
		)->run();

		$result = $this->configured(
			new TestMigration('2026_01_01_000001_create_users'),
			new TestMigration('2026_01_01_000002_create_posts'),
		)->run();

		$this->assertSame(['2026_01_01_000002_create_posts'], $result->ran);
		$this->assertSame(['2026_01_01_000001_create_users'], $result->skipped);
		$this->assertSame(2, $this->repository->all()['2026_01_01_000002_create_posts']->batch);
	}

	public function test_it_rolls_back_the_latest_batch_in_reverse_order(): void {
		$this->configured(
			new TestMigration('2026_01_01_000001_create_users'),
		)->run();
		$this->configured(
			new TestMigration('2026_01_01_000002_create_posts'),
			new TestMigration('2026_01_01_000003_create_comments'),
		)->run();

		$this->schema->statements = [];

		$result = $this->configured(
			new TestMigration('2026_01_01_000001_create_users'),
			new TestMigration('2026_01_01_000002_create_posts'),
			new TestMigration('2026_01_01_000003_create_comments'),
		)->rollback();

		$this->assertSame([
			'2026_01_01_000003_create_comments',
			'2026_01_01_000002_create_posts',
		], $result->rolledBack);
		$this->assertSame([
			'down:2026_01_01_000003_create_comments',
			'down:2026_01_01_000002_create_posts',
		], $this->schema->statements);
		$this->assertTrue($this->repository->hasRun('2026_01_01_000001_create_users'));
		$this->assertFalse($this->repository->hasRun('2026_01_01_000002_create_posts'));
	}

	public function test_it_returns_an_empty_result_when_there_is_no_batch_to_roll_back(): void {
		$result = $this->configured(
			new TestMigration('2026_01_01_000001_create_users'),
		)->rollback();

		$this->assertSame([], $result->rolledBack);
		$this->assertSame(0, $result->count());
	}

	public function test_it_rejects_unavailable_rollback_records_before_changing_schema(): void {
		$this->repository->recordRun('2026_01_01_000001_create_users', 1);
		$this->repository->recordRun('2026_01_01_000001_missing_migration', 1);

		$this->expectException(UnavailableMigration::class);
		$this->expectExceptionMessage('2026_01_01_000001_missing_migration');

		try {
			$this->configured(
				new TestMigration('2026_01_01_000001_create_users'),
			)->rollback();
		} finally {
			$this->assertSame([], $this->schema->statements);
			$this->assertTrue($this->repository->hasRun('2026_01_01_000001_create_users'));
		}
	}

	public function test_it_rejects_unavailable_refresh_records_before_changing_schema(): void {
		$this->repository->recordRun('2026_01_01_000001_create_users', 1);
		$this->repository->recordRun('2026_01_01_000002_missing_migration', 1);

		$this->expectException(UnavailableMigration::class);

		try {
			$this->configured(
				new TestMigration('2026_01_01_000001_create_users'),
			)->refresh();
		} finally {
			$this->assertSame([], $this->schema->statements);
			$this->assertTrue($this->repository->hasRun('2026_01_01_000001_create_users'));
		}
	}

	public function test_it_refreshes_all_ran_migrations_then_runs_them_again(): void {
		$migrations = $this->collection(
			new TestMigration('2026_01_01_000001_create_users'),
			new TestMigration('2026_01_01_000002_create_posts'),
		);

		$migrator = $this->migrator($migrations);
		$migrator->run();
		$this->schema->statements = [];

		$result = $migrator->refresh();

		$this->assertSame([
			'2026_01_01_000002_create_posts',
			'2026_01_01_000001_create_users',
		], $result->rolledBack);
		$this->assertSame([
			'2026_01_01_000001_create_users',
			'2026_01_01_000002_create_posts',
		], $result->ran);
		$this->assertSame([
			'down:2026_01_01_000002_create_posts',
			'down:2026_01_01_000001_create_users',
			'up:2026_01_01_000001_create_users',
			'up:2026_01_01_000002_create_posts',
		], $this->schema->statements);
	}

	public function test_refresh_uses_one_migration_snapshot_for_rollback_and_run(): void {
		$collection = new Collection();
		$late       = new TestMigration('2026_01_01_000002_create_posts');
		$migration  = $this->createMock(Migration::class);

		$migration->method('id')->willReturn('2026_01_01_000001_create_users');
		$migration->method('up')
			->willReturnCallback(static function (Schema $schema): void {
				$schema->execute('up:2026_01_01_000001_create_users');
			});
		$migration->method('down')
			->willReturnCallback(static function (Schema $schema) use ($collection, $late): void {
				$schema->execute('down:2026_01_01_000001_create_users');
				$collection->add($late);
			});

		$collection->add($migration);
		$migrator = $this->migrator($collection);
		$migrator->run();
		$result = $migrator->refresh();

		$this->assertSame(['2026_01_01_000001_create_users'], $result->rolledBack);
		$this->assertSame(['2026_01_01_000001_create_users'], $result->ran);
		$this->assertSame([$migration, $late], $collection->values());
	}

	public function test_it_returns_status_for_configured_migrations(): void {
		$this->configured(
			new TestMigration('2026_01_01_000001_create_users'),
		)->run();

		$statuses = $this->configured(
			new TestMigration('2026_01_01_000001_create_users'),
			new TestMigration('2026_01_01_000002_create_posts'),
		)->status();

		$this->assertTrue($statuses[0]->ran);
		$this->assertSame(1, $statuses[0]->batch);
		$this->assertFalse($statuses[1]->ran);
		$this->assertNull($statuses[1]->batch);
	}

	public function test_it_returns_status_for_unavailable_recorded_migrations(): void {
		$migrator = $this->configured(
			new TestMigration('2026_01_01_000002_create_posts'),
		);
		$this->repository->recordRun('2026_01_01_000001_missing_migration', 1);

		$statuses = $migrator->status();

		$this->assertTrue($statuses[0]->available);
		$this->assertFalse($statuses[0]->ran);
		$this->assertFalse($statuses[1]->available);
		$this->assertTrue($statuses[1]->ran);
		$this->assertSame('2026_01_01_000001_missing_migration', $statuses[1]->migration);
	}

	public function test_migration_results_count_ran_and_rolled_back_migrations(): void {
		$result = new Result(
			ran: ['2026_01_01_000001_create_users'],
			rolledBack: ['2026_01_01_000002_create_posts'],
			skipped: ['2026_01_01_000003_create_comments']
		);

		$this->assertSame(2, $result->count());
	}

	public function test_it_treats_migration_ids_as_case_sensitive(): void {
		$result = $this->configured(
			new TestMigration('CreateReports'),
			new TestMigration('createreports'),
		)->run();

		$this->assertSame(['CreateReports', 'createreports'], $result->ran);
		$this->assertCount(2, $this->repository->all());
	}

	public function test_it_fails_when_the_migration_lock_is_already_owned(): void {
		$this->lock->acquire('foundation-database-migrations', 300);

		$this->expectException(MigrationLockFailed::class);
		$this->expectExceptionMessage('Could not acquire migration lock');

		$this->configured(
			new TestMigration('2026_01_01_000001_create_users'),
		)->run();
	}

	public function test_it_propagates_lock_acquisition_failures_with_the_configured_policy(): void {
		$lock = $this->createMock(Lock::class);
		$lock->expects($this->once())
			->method('acquire')
			->with('custom-migrations', 120)
			->willThrowException(new LockUnavailableException('Lock backend unavailable.'));
		$lock->expects($this->never())
			->method('release');

		$migrator = $this->migrator(
			$this->collection(new TestMigration('2026_01_01_000001_create_users')),
			lock: $lock,
			lockName: 'custom-migrations',
			lockTtl: 120
		);

		$this->expectException(LockUnavailableException::class);

		$migrator->run();
	}

	public function test_it_releases_the_lock_when_initialization_fails(): void {
		$storeSchema = $this->createMock(Schema::class);
		$storeSchema->method('createOrUpdate')
			->willReturnCallback(static function (Table $table): void {
				if ($table instanceof MigrationTable) {
					throw new DatabaseException('Could not prepare the migration ledger.');
				}
			});

		$store = new Store(
			$storeSchema,
			$this->lock,
			new MigrationTable('wp_nexcess_foundation_migrations'),
			new LockTable('wp_nexcess_foundation_locks')
		);

		$this->expectException(DatabaseException::class);

		try {
			$store->initialize();
		} finally {
			$this->assertNotNull($this->lock->acquire('foundation-database-migrations', 300));
		}
	}

	public function test_it_fails_when_migration_lock_ownership_cannot_be_confirmed_during_release(): void {
		$token = $this->lockToken();
		$lock  = $this->createMock(Lock::class);
		$lock->expects($this->once())
			->method('acquire')
			->with('foundation-database-migrations', 300)
			->willReturn($token);
		$lock->expects($this->once())
			->method('release')
			->with($token)
			->willReturn(false);

		$migrator = $this->migrator(
			$this->collection(new TestMigration('2026_01_01_000001_create_users')),
			lock: $lock,
		);

		$this->expectException(MigrationLockFailed::class);
		$this->expectExceptionMessage('Could not confirm ownership');

		try {
			$migrator->run();
		} finally {
			$this->assertTrue($this->repository->hasRun('2026_01_01_000001_create_users'));
		}
	}

	public function test_it_preserves_the_migration_failure_when_lock_release_is_unavailable(): void {
		$token = $this->lockToken();
		$lock  = $this->createMock(Lock::class);
		$lock->expects($this->once())
			->method('acquire')
			->willReturn($token);
		$lock->expects($this->once())
			->method('release')
			->with($token)
			->willThrowException(new LockUnavailableException('Lock backend unavailable.'));

		$migrator = $this->migrator(
			$this->collection(new FailingMigration('2026_01_01_000001_create_users', failUp: true)),
			lock: $lock,
		);

		$this->expectException(MigrationFailed::class);
		$this->expectExceptionMessage('failed while running');

		$migrator->run();
	}

	public function test_it_propagates_lock_release_failures_after_a_successful_migration(): void {
		$token = $this->lockToken();
		$lock  = $this->createMock(Lock::class);
		$lock->expects($this->once())
			->method('acquire')
			->willReturn($token);
		$lock->expects($this->once())
			->method('release')
			->with($token)
			->willThrowException(new LockUnavailableException('Lock backend unavailable.'));

		$migrator = $this->migrator(
			$this->collection(new TestMigration('2026_01_01_000001_create_users')),
			lock: $lock,
		);

		$this->expectException(LockUnavailableException::class);

		try {
			$migrator->run();
		} finally {
			$this->assertTrue($this->repository->hasRun('2026_01_01_000001_create_users'));
		}
	}

	public function test_it_preserves_the_migration_failure_when_release_cannot_confirm_ownership(): void {
		$token = $this->lockToken();
		$lock  = $this->createMock(Lock::class);
		$lock->expects($this->once())
			->method('acquire')
			->willReturn($token);
		$lock->expects($this->once())
			->method('release')
			->with($token)
			->willReturn(false);

		$migrator = $this->migrator(
			$this->collection(new FailingMigration('2026_01_01_000001_create_users', failUp: true)),
			lock: $lock,
		);

		$this->expectException(MigrationFailed::class);
		$this->expectExceptionMessage('failed while running');

		$migrator->run();
	}

	public function test_it_does_not_record_a_failed_migration(): void {
		$this->expectException(MigrationFailed::class);
		$this->expectExceptionMessage('failed while running');

		try {
			$this->configured(
				new FailingMigration('2026_01_01_000001_create_users', failUp: true),
			)->run();
		} finally {
			$this->assertFalse($this->repository->hasRun('2026_01_01_000001_create_users'));
		}
	}

	public function test_it_does_not_delete_a_record_when_rollback_fails(): void {
		$this->configured(
			new TestMigration('2026_01_01_000001_create_users'),
		)->run();

		$this->expectException(MigrationFailed::class);
		$this->expectExceptionMessage('failed while rolling back');

		try {
			$this->configured(
				new FailingMigration('2026_01_01_000001_create_users', failDown: true),
			)->rollback();
		} finally {
			$this->assertTrue($this->repository->hasRun('2026_01_01_000001_create_users'));
		}
	}

	private function lockToken(): LockToken {
		$token = $this->lock->acquire('foundation-database-migrations', 300);

		$this->assertNotNull($token);

		return $token;
	}

	private function configured(Migration ...$migrations): Migrator {
		return $this->migrator($this->collection(...$migrations));
	}

	private function migrator(
		Collection $migrations,
		?Lock $lock = null,
		?Schema $schema = null,
		string $lockName = 'foundation-database-migrations',
		int $lockTtl = 300
	): Migrator {
		$schema ??= $this->schema;
		$lock ??= $this->lock;
		$store = new Store(
			$schema,
			$lock,
			new MigrationTable('wp_nexcess_foundation_migrations'),
			new LockTable('wp_nexcess_foundation_locks'),
			$lockName,
			$lockTtl
		);

		return new Migrator(
			$migrations,
			$this->repository,
			$store
		);
	}

	private function collection(Migration ...$migrations): Collection {
		return new Collection($migrations);
	}
}
