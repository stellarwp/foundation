<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration;

use StellarWP\Foundation\Database\Exceptions\DuplicateMigration;
use StellarWP\Foundation\Database\Exceptions\MigrationFailed;
use StellarWP\Foundation\Database\Exceptions\MigrationLockFailed;
use StellarWP\Foundation\Database\Migration\Result;
use StellarWP\Foundation\Database\Migration\Runner;
use StellarWP\Foundation\Lock\InMemoryLock;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FailingMigration;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\InMemoryRepository;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchema;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestMigration;
use StellarWP\Foundation\Tests\Support\Fixtures\Lock\MutableClock;
use StellarWP\Foundation\Tests\TestCase;

final class RunnerTest extends TestCase
{
	private InMemoryRepository $repository;

	private RecordingSchema $schema;

	private InMemoryLock $lock;

	private Runner $runner;

	protected function setUp(): void {
		parent::setUp();

		$this->repository = new InMemoryRepository();
		$this->schema     = new RecordingSchema();
		$this->lock       = new InMemoryLock(new MutableClock(new \DateTimeImmutable('2026-01-01 00:00:00')));
		$this->runner     = new Runner($this->repository, $this->schema, $this->lock);
	}

	public function test_it_runs_pending_migrations_in_order(): void {
		$result = $this->runner->run([
			new TestMigration('2026_01_01_000001_create_users'),
			new TestMigration('2026_01_01_000002_create_posts'),
		]);

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
		$this->runner->run([
			new TestMigration('2026_01_01_000001_create_users'),
		]);

		$result = $this->runner->run([
			new TestMigration('2026_01_01_000001_create_users'),
			new TestMigration('2026_01_01_000002_create_posts'),
		]);

		$this->assertSame(['2026_01_01_000002_create_posts'], $result->ran);
		$this->assertSame(['2026_01_01_000001_create_users'], $result->skipped);
		$this->assertSame(2, $this->repository->all()['2026_01_01_000002_create_posts']->batch);
	}

	public function test_it_rolls_back_the_latest_batch_in_reverse_order(): void {
		$this->runner->run([
			new TestMigration('2026_01_01_000001_create_users'),
		]);
		$this->runner->run([
			new TestMigration('2026_01_01_000002_create_posts'),
			new TestMigration('2026_01_01_000003_create_comments'),
		]);

		$this->schema->statements = [];

		$result = $this->runner->rollback([
			new TestMigration('2026_01_01_000001_create_users'),
			new TestMigration('2026_01_01_000002_create_posts'),
			new TestMigration('2026_01_01_000003_create_comments'),
		]);

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
		$result = $this->runner->rollback([
			new TestMigration('2026_01_01_000001_create_users'),
		]);

		$this->assertSame([], $result->rolledBack);
		$this->assertSame(0, $result->count());
	}

	public function test_it_skips_rollback_records_without_a_matching_migration(): void {
		$this->repository->recordRun('2026_01_01_000001_missing_migration', 1);

		$result = $this->runner->rollback([
			new TestMigration('2026_01_01_000002_create_posts'),
		]);

		$this->assertSame([], $result->rolledBack);
		$this->assertSame(['2026_01_01_000001_missing_migration'], $result->skipped);
	}

	public function test_it_refreshes_all_ran_migrations_then_runs_them_again(): void {
		$migrations = [
			new TestMigration('2026_01_01_000001_create_users'),
			new TestMigration('2026_01_01_000002_create_posts'),
		];

		$this->runner->run($migrations);
		$this->schema->statements = [];

		$result = $this->runner->refresh($migrations);

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

	public function test_it_returns_status_for_configured_migrations(): void {
		$this->runner->run([
			new TestMigration('2026_01_01_000001_create_users'),
		]);

		$statuses = $this->runner->status([
			new TestMigration('2026_01_01_000001_create_users'),
			new TestMigration('2026_01_01_000002_create_posts'),
		]);

		$this->assertTrue($statuses[0]->ran);
		$this->assertSame(1, $statuses[0]->batch);
		$this->assertFalse($statuses[1]->ran);
		$this->assertNull($statuses[1]->batch);
	}

	public function test_migration_results_count_ran_and_rolled_back_migrations(): void {
		$result = new Result(
			ran: ['2026_01_01_000001_create_users'],
			rolledBack: ['2026_01_01_000002_create_posts'],
			skipped: ['2026_01_01_000003_create_comments']
		);

		$this->assertSame(2, $result->count());
	}

	public function test_it_rejects_duplicate_migration_ids(): void {
		$this->expectException(DuplicateMigration::class);
		$this->expectExceptionMessage('Duplicate migration ID');

		$this->runner->run([
			new TestMigration('2026_01_01_000001_create_users'),
			new TestMigration('2026_01_01_000001_create_users'),
		]);
	}

	public function test_it_fails_when_the_migration_lock_is_already_owned(): void {
		$this->lock->acquire('foundation-database-migrations', 300);

		$this->expectException(MigrationLockFailed::class);
		$this->expectExceptionMessage('Could not acquire migration lock');

		$this->runner->run([
			new TestMigration('2026_01_01_000001_create_users'),
		]);
	}

	public function test_it_does_not_record_a_failed_migration(): void {
		$this->expectException(MigrationFailed::class);
		$this->expectExceptionMessage('failed while running');

		try {
			$this->runner->run([
				new FailingMigration('2026_01_01_000001_create_users', failUp: true),
			]);
		} finally {
			$this->assertFalse($this->repository->hasRun('2026_01_01_000001_create_users'));
		}
	}

	public function test_it_does_not_delete_a_record_when_rollback_fails(): void {
		$this->runner->run([
			new TestMigration('2026_01_01_000001_create_users'),
		]);

		$this->expectException(MigrationFailed::class);
		$this->expectExceptionMessage('failed while rolling back');

		try {
			$this->runner->rollback([
				new FailingMigration('2026_01_01_000001_create_users', failDown: true),
			]);
		} finally {
			$this->assertTrue($this->repository->hasRun('2026_01_01_000001_create_users'));
		}
	}
}
