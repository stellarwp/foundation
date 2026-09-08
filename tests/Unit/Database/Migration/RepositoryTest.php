<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration;

use StellarWP\Foundation\Database\Migration\Exceptions\InvalidMigrationId;
use StellarWP\Foundation\Database\Migration\Exceptions\LedgerFailure;
use StellarWP\Foundation\Database\Migration\Repository;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\TestCase;

final class RepositoryTest extends TestCase
{
	private FakeDatabase $database;

	private Repository $repository;

	protected function setUp(): void {
		parent::setUp();

		$this->database   = new FakeDatabase();
		$this->repository = new Repository(
			new MigrationTable('network_foundation_migrations', $this->database)
		);
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
		$this->assertStringEndsWith('ORDER BY `id` ASC', $this->database->rowsQueries[0]);
	}

	public function test_it_records_a_migration_run(): void {
		$this->repository->recordRun('2026_01_01_000001_create_users', 2);

		$this->assertSame('INSERT wp_network_foundation_migrations', $this->database->executed[0]);
		$this->assertSame([], $this->database->rowQueries);
	}

	public function test_it_rejects_an_unconfirmed_migration_run(): void {
		$this->database->insertResult = 0;

		$this->expectException(LedgerFailure::class);
		$this->expectExceptionMessage('ran but its ledger record was not inserted');

		$this->repository->recordRun('2026_01_01_000001_create_users', 2);
	}

	public function test_it_rejects_invalid_migration_ids_before_writing_to_the_ledger(): void {
		$this->expectException(InvalidMigrationId::class);

		$this->repository->recordRun(' invalid', 2);
	}

	public function test_it_rejects_invalid_migration_ids_read_from_the_ledger(): void {
		$this->database->rowsResults[] = [[
			'id'        => 1,
			'migration' => '123',
			'batch'     => 1,
			'ran_at'    => '2026-01-01 00:00:00',
		]];

		$this->expectException(InvalidMigrationId::class);

		$this->repository->all();
	}

	public function test_it_deletes_a_migration_run(): void {
		$this->database->executeResults[] = 1;

		$this->assertTrue($this->repository->deleteRun('2026_01_01_000001_create_users'));
		$this->assertSame('DELETE wp_network_foundation_migrations', $this->database->executed[0]);
	}

	public function test_it_reports_when_no_migration_run_was_deleted(): void {
		$this->database->executeResults[] = 0;

		$this->assertFalse($this->repository->deleteRun('2026_01_01_000001_create_users'));
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
		$this->assertStringEndsWith('ORDER BY `id` ASC', $this->database->rowsQueries[0]);
	}
}
