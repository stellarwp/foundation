<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration;

use DateTimeImmutable;
use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Repository;
use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Exceptions\DatabaseContextChanged;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\MigrationFailed;
use StellarWP\Foundation\Database\Exceptions\MigrationLockFailed;
use StellarWP\Foundation\Database\Migration\Collection;
use StellarWP\Foundation\Database\Migration\Exceptions\InvalidRollbackBatch;
use StellarWP\Foundation\Database\Migration\Exceptions\LedgerFailure;
use StellarWP\Foundation\Database\Migration\Exceptions\UnavailableMigration;
use StellarWP\Foundation\Database\Migration\Factories\LeaseFactory;
use StellarWP\Foundation\Database\Migration\Factories\SessionFactory;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Migration\Store;
use StellarWP\Foundation\Database\Migration\ValueObjects\Record;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\Lock\InMemoryLock;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\ContextChangingMigration;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FailingMigration;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\InMemoryRepository;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchema;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestDatabaseScope;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestMigration;
use StellarWP\Foundation\Tests\Support\Fixtures\Lock\MutableClock;
use StellarWP\Foundation\Tests\TestCase;

final class MigratorExecutionTest extends TestCase
{
	private InMemoryRepository $repository;

	private RecordingSchema $schema;

	private MutableClock $clock;

	private InMemoryLock $lock;

	private TestDatabaseScope $scope;

	private FakeDatabase $database;

	protected function setUp(): void {
		parent::setUp();

		$this->repository = new InMemoryRepository();
		$this->schema     = new RecordingSchema();
		$this->clock      = new MutableClock(new DateTimeImmutable('2026-01-01 00:00:00'));
		$this->lock       = new InMemoryLock($this->clock);
		$this->scope      = new TestDatabaseScope();
		$this->database   = new FakeDatabase();

		(new Store(
			$this->schema,
			new LeaseFactory(),
			new SessionFactory(),
			$this->scope,
			$this->lock,
			new MigrationTable('nx_foundation_migrations', $this->database),
			new LockTable('nx_foundation_locks', $this->database)
		))->initialize();

		$this->schema->statements = [];
	}

	public function test_it_rejects_a_blank_migration_lock_name(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('lock name cannot be empty');

		new Store(
			$this->schema,
			new LeaseFactory(),
			new SessionFactory(),
			$this->scope,
			$this->lock,
			new MigrationTable('nx_foundation_migrations', $this->database),
			new LockTable('nx_foundation_locks', $this->database),
			lockName: '   '
		);
	}

	public function test_it_rejects_an_invalid_migration_lock_ttl(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('TTL must be at least one second');

		new Store(
			$this->schema,
			new LeaseFactory(),
			new SessionFactory(),
			$this->scope,
			$this->lock,
			new MigrationTable('nx_foundation_migrations', $this->database),
			new LockTable('nx_foundation_locks', $this->database),
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

	public function test_it_renews_the_lock_around_each_migration(): void {
		$first = $this->createMock(Migration::class);
		$first->method('id')->willReturn('2026_01_01_000001_create_users');
		$first->method('up')->willReturnCallback(function (Schema $schema): void {
			$schema->execute('up:2026_01_01_000001_create_users');
			$this->clock->advance(9);
		});

		$second = $this->createMock(Migration::class);
		$second->method('id')->willReturn('2026_01_01_000002_create_posts');
		$second->method('up')->willReturnCallback(function (Schema $schema): void {
			$schema->execute('up:2026_01_01_000002_create_posts');
			$this->clock->advance(9);
		});

		$result = $this->migrator(
			$this->collection($first, $second),
			lockTtl: 10
		)->run();

		$this->assertSame([
			'2026_01_01_000001_create_users',
			'2026_01_01_000002_create_posts',
		], $result->ran);
	}

	public function test_it_does_not_record_a_migration_after_its_lock_expires(): void {
		$migration = $this->createMock(Migration::class);
		$migration->method('id')->willReturn('2026_01_01_000001_create_users');
		$migration->method('up')->willReturnCallback(function (Schema $schema): void {
			$schema->execute('up:2026_01_01_000001_create_users');
			$this->clock->advance(10);
		});

		$this->expectException(MigrationLockFailed::class);
		$this->expectExceptionMessage('Could not refresh migration lock');

		try {
			$this->migrator(
				$this->collection($migration),
				lockTtl: 10
			)->run();
		} finally {
			$this->assertSame(['up:2026_01_01_000001_create_users'], $this->schema->statements);
			$this->assertFalse($this->repository->hasRun('2026_01_01_000001_create_users'));
		}
	}

	public function test_it_releases_the_latest_token_when_post_migration_renewal_loses_ownership(): void {
		$migration = new TestMigration('2026_01_01_000001_create_users');
		$acquired  = new LockToken('custom-migrations', 'owner', new DateTimeImmutable('2026-01-01 00:02:00'));
		$beforeRun = $acquired->withExpiration(new DateTimeImmutable('2026-01-01 00:04:00'));
		$lock      = $this->createMock(Lock::class);
		$lock->expects($this->once())
			->method('acquire')
			->with('custom-migrations', 120)
			->willReturn($acquired);
		$lock->expects($this->exactly(2))
			->method('refresh')
			->willReturnOnConsecutiveCalls($beforeRun, null);
		$lock->expects($this->once())
			->method('release')
			->with($beforeRun)
			->willReturn(true);

		$this->expectException(MigrationLockFailed::class);
		$this->expectExceptionMessage('ownership was lost');

		try {
			$this->migrator(
				$this->collection($migration),
				lock: $lock,
				lockName: 'custom-migrations',
				lockTtl: 120
			)->run();
		} finally {
			$this->assertSame(['up:' . $migration->id()], $this->schema->statements);
			$this->assertFalse($this->repository->hasRun($migration->id()));
		}
	}

	public function test_it_does_not_release_in_a_database_scope_changed_during_renewal(): void {
		$migration = new TestMigration('2026_01_01_000001_create_users');
		$token     = $this->lockToken();
		$lock      = $this->createMock(Lock::class);
		$lock->expects($this->once())
			->method('acquire')
			->willReturn($token);
		$lock->expects($this->once())
			->method('refresh')
			->with($token, 300)
			->willReturnCallback(function () use ($token): LockToken {
				$this->scope->currentId = 2;

				return $token;
			});
		$lock->expects($this->never())->method('release');

		$this->expectException(DatabaseContextChanged::class);

		try {
			$this->migrator($this->collection($migration), lock: $lock)->run();
		} finally {
			$this->assertSame([], $this->schema->statements);
			$this->assertFalse($this->repository->hasRun($migration->id()));
		}
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

	public function test_it_renews_the_lock_around_each_rollback(): void {
		$first = $this->createMock(Migration::class);
		$first->method('id')->willReturn('2026_01_01_000001_create_users');
		$first->method('down')->willReturnCallback(function (Schema $schema): void {
			$schema->execute('down:2026_01_01_000001_create_users');
			$this->clock->advance(9);
		});

		$second = $this->createMock(Migration::class);
		$second->method('id')->willReturn('2026_01_01_000002_create_posts');
		$second->method('down')->willReturnCallback(function (Schema $schema): void {
			$schema->execute('down:2026_01_01_000002_create_posts');
			$this->clock->advance(9);
		});

		$migrator = $this->migrator(
			$this->collection($first, $second),
			lockTtl: 10
		);
		$migrator->run();

		$result = $migrator->rollback();

		$this->assertSame([
			'2026_01_01_000002_create_posts',
			'2026_01_01_000001_create_users',
		], $result->rolledBack);
	}

	public function test_it_preserves_a_migration_record_when_its_rollback_lock_expires(): void {
		$migration = $this->createMock(Migration::class);
		$migration->method('id')->willReturn('2026_01_01_000001_create_users');
		$migration->method('down')->willReturnCallback(function (Schema $schema): void {
			$schema->execute('down:2026_01_01_000001_create_users');
			$this->clock->advance(10);
		});

		$this->configured(new TestMigration('2026_01_01_000001_create_users'))->run();
		$this->schema->statements = [];

		$this->expectException(MigrationLockFailed::class);
		$this->expectExceptionMessage('Could not refresh migration lock');

		try {
			$this->migrator(
				$this->collection($migration),
				lockTtl: 10
			)->rollback();
		} finally {
			$this->assertSame(['down:2026_01_01_000001_create_users'], $this->schema->statements);
			$this->assertTrue($this->repository->hasRun('2026_01_01_000001_create_users'));
		}
	}

	public function test_it_rolls_back_an_explicit_batch_when_it_is_still_latest(): void {
		$this->configured(
			new TestMigration('2026_01_01_000001_create_users'),
		)->run();
		$this->configured(
			new TestMigration('2026_01_01_000002_create_posts'),
		)->run();

		$result = $this->configured(
			new TestMigration('2026_01_01_000001_create_users'),
			new TestMigration('2026_01_01_000002_create_posts'),
		)->rollback(2);

		$this->assertSame(['2026_01_01_000002_create_posts'], $result->rolledBack);
		$this->assertTrue($this->repository->hasRun('2026_01_01_000001_create_users'));
		$this->assertFalse($this->repository->hasRun('2026_01_01_000002_create_posts'));
	}

	public function test_it_rejects_rolling_back_an_older_batch(): void {
		$this->configured(
			new TestMigration('2026_01_01_000001_create_users'),
		)->run();
		$this->configured(
			new TestMigration('2026_01_01_000002_create_posts'),
		)->run();

		$this->schema->statements = [];

		$this->expectException(InvalidRollbackBatch::class);
		$this->expectExceptionMessage('batch 1 cannot be rolled back because the latest recorded batch is 2');

		try {
			$this->configured(
				new TestMigration('2026_01_01_000001_create_users'),
				new TestMigration('2026_01_01_000002_create_posts'),
			)->rollback(1);
		} finally {
			$this->assertSame([], $this->schema->statements);
			$this->assertTrue($this->repository->hasRun('2026_01_01_000001_create_users'));
			$this->assertTrue($this->repository->hasRun('2026_01_01_000002_create_posts'));
		}
	}

	public function test_it_returns_an_empty_result_when_there_is_no_batch_to_roll_back(): void {
		$result = $this->configured(
			new TestMigration('2026_01_01_000001_create_users'),
		)->rollback();

		$this->assertSame([], $result->rolledBack);
		$this->assertSame(0, $result->count());
	}

	public function test_it_rejects_an_explicit_batch_when_no_batch_remains(): void {
		$this->expectException(InvalidRollbackBatch::class);
		$this->expectExceptionMessage('batch 1 cannot be rolled back because the latest recorded batch is none');

		$this->configured(
			new TestMigration('2026_01_01_000001_create_users'),
		)->rollback(1);
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

	public function test_it_renews_one_lock_through_both_phases_of_refresh(): void {
		$migration       = new TestMigration('2026_01_01_000001_create_users');
		$acquired        = new LockToken('custom-migrations', 'owner', new DateTimeImmutable('2026-01-01 00:01:00'));
		$beforeRollback  = $acquired->withExpiration(new DateTimeImmutable('2026-01-01 00:02:00'));
		$afterRollback   = $acquired->withExpiration(new DateTimeImmutable('2026-01-01 00:03:00'));
		$beforeRun       = $acquired->withExpiration(new DateTimeImmutable('2026-01-01 00:04:00'));
		$afterRun        = $acquired->withExpiration(new DateTimeImmutable('2026-01-01 00:05:00'));
		$expectedTokens  = [$acquired, $beforeRollback, $afterRollback, $beforeRun];
		$refreshedTokens = [$beforeRollback, $afterRollback, $beforeRun, $afterRun];
		$lock            = $this->createMock(Lock::class);

		$this->repository->recordRun($migration->id(), 1);
		$lock->expects($this->once())
			->method('acquire')
			->with('custom-migrations', 10)
			->willReturn($acquired);
		$lock->expects($this->exactly(4))
			->method('refresh')
			->willReturnCallback(function (LockToken $token, int $ttl) use (&$expectedTokens, &$refreshedTokens): ?LockToken {
				$this->assertSame(array_shift($expectedTokens), $token);
				$this->assertSame(10, $ttl);

				return array_shift($refreshedTokens);
			});
		$lock->expects($this->once())
			->method('release')
			->with($afterRun)
			->willReturn(true);

		$migrator = $this->migrator(
			$this->collection($migration),
			lock: $lock,
			lockName: 'custom-migrations',
			lockTtl: 10
		);

		$result = $migrator->refresh();

		$this->assertSame(['2026_01_01_000001_create_users'], $result->rolledBack);
		$this->assertSame(['2026_01_01_000001_create_users'], $result->ran);
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

	public function test_it_treats_migration_ids_as_case_sensitive(): void {
		$result = $this->configured(
			new TestMigration('CreateReports'),
			new TestMigration('createreports'),
		)->run();

		$this->assertSame(['CreateReports', 'createreports'], $result->ran);
		$this->assertCount(2, $this->repository->all());
	}

	public function test_it_fails_when_the_migration_lock_is_already_owned(): void {
		$this->lock->acquire('nx-foundation-database-migrations', 300);

		$this->expectException(MigrationLockFailed::class);
		$this->expectExceptionMessage('Could not acquire migration lock');

		$this->configured(
			new TestMigration('2026_01_01_000001_create_users'),
		)->run();
	}

	public function test_it_reports_a_scope_change_before_lock_contention(): void {
		$lock = $this->createMock(Lock::class);
		$lock->expects($this->once())
			->method('acquire')
			->willReturnCallback(function (): null {
				$this->scope->currentId = 2;

				return null;
			});
		$lock->expects($this->never())->method('release');

		$this->expectException(DatabaseContextChanged::class);

		$this->migrator(new Collection(), lock: $lock)->run();
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

	public function test_it_releases_the_lock_when_renewal_fails_before_a_migration_runs(): void {
		$migration = new TestMigration('2026_01_01_000001_create_users');
		$token     = new LockToken('custom-migrations', 'owner', new DateTimeImmutable('2026-01-01 00:02:00'));
		$lock      = $this->createMock(Lock::class);

		$lock->expects($this->once())
			->method('acquire')
			->with('custom-migrations', 120)
			->willReturn($token);
		$lock->expects($this->once())
			->method('refresh')
			->with($token, 120)
			->willThrowException(new LockUnavailableException('Lock backend unavailable.'));
		$lock->expects($this->once())
			->method('release')
			->with($token)
			->willReturn(true);

		$this->expectException(LockUnavailableException::class);

		try {
			$this->migrator(
				$this->collection($migration),
				lock: $lock,
				lockName: 'custom-migrations',
				lockTtl: 120
			)->run();
		} finally {
			$this->assertSame([], $this->schema->statements);
			$this->assertFalse($this->repository->hasRun($migration->id()));
		}
	}

	public function test_it_uses_the_configured_ttl_and_releases_the_latest_refreshed_token(): void {
		$acquired  = new LockToken('custom-migrations', 'owner', new DateTimeImmutable('2026-01-01 00:02:00'));
		$beforeRun = $acquired->withExpiration(new DateTimeImmutable('2026-01-01 00:04:00'));
		$afterRun  = $acquired->withExpiration(new DateTimeImmutable('2026-01-01 00:06:00'));
		$expected  = [$acquired, $beforeRun];
		$refreshed = [$beforeRun, $afterRun];
		$lock      = $this->createMock(Lock::class);

		$lock->expects($this->once())
			->method('acquire')
			->with('custom-migrations', 120)
			->willReturn($acquired);
		$lock->expects($this->exactly(2))
			->method('refresh')
			->willReturnCallback(function (LockToken $token, int $ttl) use (&$expected, &$refreshed): ?LockToken {
				$this->assertSame(array_shift($expected), $token);
				$this->assertSame(120, $ttl);

				return array_shift($refreshed);
			});
		$lock->expects($this->once())
			->method('release')
			->with($afterRun)
			->willReturn(true);

		$this->migrator(
			$this->collection(new TestMigration('2026_01_01_000001_create_users')),
			lock: $lock,
			lockName: 'custom-migrations',
			lockTtl: 120
		)->run();
	}

	public function test_it_stops_before_running_a_migration_when_lock_renewal_loses_ownership(): void {
		$token = $this->lockToken();
		$lock  = $this->createMock(Lock::class);
		$lock->expects($this->once())
			->method('acquire')
			->willReturn($token);
		$lock->expects($this->once())
			->method('refresh')
			->with($token, 300)
			->willReturn(null);
		$lock->expects($this->once())
			->method('release')
			->with($token)
			->willReturn(false);

		$this->expectException(MigrationLockFailed::class);
		$this->expectExceptionMessage('Could not refresh migration lock');

		try {
			$this->migrator(
				$this->collection(new TestMigration('2026_01_01_000001_create_users')),
				lock: $lock,
			)->run();
		} finally {
			$this->assertSame([], $this->schema->statements);
			$this->assertFalse($this->repository->hasRun('2026_01_01_000001_create_users'));
		}
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
			new LeaseFactory(),
			new SessionFactory(),
			$this->scope,
			$this->lock,
			new MigrationTable('nx_foundation_migrations', $this->database),
			new LockTable('nx_foundation_locks', $this->database)
		);

		$this->expectException(DatabaseException::class);

		try {
			$store->initialize();
		} finally {
			$this->assertNotNull($this->lock->acquire('nx-foundation-database-migrations', 300));
		}
	}

	public function test_it_fails_when_migration_lock_ownership_cannot_be_confirmed_during_release(): void {
		$token = $this->lockToken();
		$lock  = $this->createMock(Lock::class);
		$lock->expects($this->once())
			->method('acquire')
			->with('nx-foundation-database-migrations', 300)
			->willReturn($token);
		$lock->expects($this->exactly(2))
			->method('refresh')
			->with($token, 300)
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

	public function test_it_reports_a_scope_change_during_lock_release(): void {
		$token = new LockToken(
			'nx-foundation-database-migrations',
			'owner',
			new DateTimeImmutable('2026-01-01 00:05:00')
		);
		$lock = $this->createMock(Lock::class);
		$lock->expects($this->once())->method('acquire')->willReturn($token);
		$lock->expects($this->once())
			->method('release')
			->with($token)
			->willReturnCallback(function (): bool {
				$this->scope->currentId = 2;

				return true;
			});

		$this->expectException(DatabaseContextChanged::class);

		$this->migrator(new Collection(), lock: $lock)->run();
	}

	public function test_it_preserves_the_migration_failure_when_lock_release_is_unavailable(): void {
		$token = $this->lockToken();
		$lock  = $this->createMock(Lock::class);
		$lock->expects($this->once())
			->method('acquire')
			->willReturn($token);
		$lock->expects($this->once())
			->method('refresh')
			->with($token, 300)
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
		$lock->expects($this->exactly(2))
			->method('refresh')
			->with($token, 300)
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
			->method('refresh')
			->with($token, 300)
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

	public function test_it_aborts_when_a_migration_changes_the_database_scope(): void {
		$migration = new ContextChangingMigration(
			'2026_01_01_000001_change_context',
			$this->scope,
			2
		);

		$this->expectException(DatabaseContextChanged::class);

		try {
			$this->configured($migration)->run();
		} finally {
			$this->assertFalse($this->repository->hasRun($migration->id()));
			$this->assertTrue($this->lock->isAcquired('nx-foundation-database-migrations'));
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

	public function test_it_fails_when_a_rolled_back_ledger_record_is_not_deleted(): void {
		$migration  = new TestMigration('2026_01_01_000001_create_users');
		$repository = $this->createMock(Repository::class);
		$repository->method('latestBatch')->willReturn(1);
		$repository->method('recordsForBatch')->willReturn([
			new Record(1, $migration->id(), 1, new DateTimeImmutable('2026-01-01 00:00:00')),
		]);
		$repository->expects($this->once())
			->method('deleteRun')
			->with($migration->id())
			->willReturn(false);

		$this->expectException(LedgerFailure::class);
		$this->expectExceptionMessage('was rolled back but its ledger record was not deleted');

		try {
			$this->migrator($this->collection($migration), repository: $repository)->rollback();
		} finally {
			$this->assertSame(['down:' . $migration->id()], $this->schema->statements);
		}
	}

	private function lockToken(): LockToken {
		$token = $this->lock->acquire('nx-foundation-database-migrations', 300);

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
		?Repository $repository = null,
		string $lockName = 'nx-foundation-database-migrations',
		int $lockTtl = 300
	): Migrator {
		$schema ??= $this->schema;
		$lock ??= $this->lock;
		$repository ??= $this->repository;
		$store = new Store(
			$schema,
			new LeaseFactory(),
			new SessionFactory(),
			$this->scope,
			$lock,
			new MigrationTable('nx_foundation_migrations', $this->database),
			new LockTable('nx_foundation_locks', $this->database),
			$lockName,
			$lockTtl
		);

		return new Migrator(
			$migrations,
			$repository,
			$store
		);
	}

	private function collection(Migration ...$migrations): Collection {
		return new Collection($migrations);
	}
}
