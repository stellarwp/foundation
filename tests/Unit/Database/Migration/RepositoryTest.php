<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration;

use StellarWP\Foundation\Database\Migration\Repository;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\TestCase;

final class RepositoryTest extends TestCase
{
	private FakeDatabase $database;

	private Repository $repository;

	protected function setUp(): void {
		parent::setUp();

		$this->database   = new FakeDatabase();
		$this->repository = new Repository($this->database, 'wp_nexcess_foundation_migrations');
	}

	public function test_it_returns_all_migration_records_indexed_by_migration_id(): void {
		$this->database->rowsResults[] = [
			[
				'id'        => 1,
				'migration' => '2026_01_01_000001_create_users',
				'batch'     => 1,
				'ran_at'    => '2026-01-01 00:00:00',
			],
		];

		$records = $this->repository->all();

		$this->assertArrayHasKey('2026_01_01_000001_create_users', $records);
		$this->assertSame(1, $records['2026_01_01_000001_create_users']->id);
	}

	public function test_it_records_a_migration_run(): void {
		$this->database->rowResults[] = [
			'id'        => 1,
			'migration' => '2026_01_01_000001_create_users',
			'batch'     => 2,
			'ran_at'    => '2026-01-01 00:00:00',
		];

		$record = $this->repository->recordRun('2026_01_01_000001_create_users', 2);

		$this->assertSame(2, $record->batch);
		$this->assertStringContainsString('INSERT INTO `wp_nexcess_foundation_migrations`', $this->database->executed[0]);
	}

	public function test_it_deletes_a_migration_run(): void {
		$this->database->executeResults[] = 1;

		$this->assertTrue($this->repository->deleteRun('2026_01_01_000001_create_users'));
		$this->assertStringContainsString('DELETE FROM `wp_nexcess_foundation_migrations`', $this->database->executed[0]);
	}

	public function test_it_calculates_the_next_batch(): void {
		$this->database->rowResults[] = ['batch' => 4];

		$this->assertSame(5, $this->repository->nextBatch());
	}

	public function test_it_returns_records_for_a_batch(): void {
		$this->database->rowsResults[] = [
			[
				'id'        => 2,
				'migration' => '2026_01_01_000002_create_posts',
				'batch'     => 3,
				'ran_at'    => '2026-01-01 00:00:00',
			],
		];

		$records = $this->repository->recordsForBatch(3);

		$this->assertCount(1, $records);
		$this->assertSame('2026_01_01_000002_create_posts', $records[0]->migration);
	}
}
