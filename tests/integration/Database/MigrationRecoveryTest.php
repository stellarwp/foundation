<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Database;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Database\Migration\Contracts\Repository;
use StellarWP\Foundation\Database\Migration\Exceptions\LedgerFailure;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Table\Blueprint;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\AddRecoveryStatusMigration;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecoveryTable;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;

final class MigrationRecoveryTest extends WPTestCase
{
	public function test_it_recovers_after_schema_changes_succeed_but_the_ledger_write_fails(): void {
		$suffix    = uniqid();
		$container = (new ContainerFactory())->create(new ArrayConfiguration([
			'database' => [
				'migrations_table' => 'recovery_migrations_' . $suffix,
				'locks_table'      => 'recovery_locks_' . $suffix,
			],
		]));
		$container->register(DatabaseProvider::class);
		$container->mergeArrayVar(DatabaseProvider::MIGRATIONS, static fn (C $c): array => [
			$c->get(AddRecoveryStatusMigration::class),
		]);

		$database       = $container->get(Database::class);
		$schema         = $container->get(Schema::class);
		$table          = $container->get(RecoveryTable::class);
		$migration      = $container->get(AddRecoveryStatusMigration::class);
		$migrationTable = $container->get(MigrationTable::class);
		$lockTable      = $container->get(LockTable::class);
		$ledger         = $container->get(Repository::class);
		$failure        = LedgerFailure::notInsertedAfterRun($migration->id());
		$attempts       = 0;
		$repository     = $this->createMock(Repository::class);
		$repository->method('all')->willReturnCallback($ledger->all(...));
		$repository->method('latestBatch')->willReturnCallback($ledger->latestBatch(...));
		$repository->expects($this->exactly(2))->method('recordRun')
			->with($migration->id(), 1)
			->willReturnCallback(static function (string $id, int $batch) use ($ledger, $failure, &$attempts): void {
				if (++$attempts === 1) {
					throw $failure;
				}

				$ledger->recordRun($id, $batch);
			});
		$container->singleton(Repository::class, $repository);
		$migrator = $container->get(Migrator::class);

		$statements = [];
		$recordDdl  = static function (string $sql) use (&$statements): string {
			if (preg_match('/\A\s*(?:CREATE|ALTER|DROP)\b/i', $sql) === 1) {
				$statements[] = $sql;
			}

			return $sql;
		};

		try {
			$initial = Blueprint::for($table);
			$initial->bigIncrements('id');
			$initial->string('name', 50);
			$initial->index('lookup', 'name');
			$schema->create($initial);
			$id = $table->insertGetId(['name' => 'Existing item']);
			$migrator->initialize();

			try {
				$migrator->run();
				$this->fail('Expected the ledger write to fail after the schema alteration.');
			} catch (LedgerFailure $exception) {
				$this->assertSame($failure, $exception);
			}

			$expected = [['id' => (string) $id, 'name' => 'Existing item', 'status' => 'pending']];
			$this->assertSame($expected, $table->query()->get());
			$this->assertSame(['status', 'name'], array_column(
				$database->rows('SHOW INDEX FROM %i WHERE Key_name = %s', $table->name(), 'lookup'),
				'Column_name'
			));
			$this->assertSame([], $migrationTable->query()->get());
			$this->assertTrue($migrator->status()[0]->isPending());

			add_filter('query', $recordDdl);
			$result = $migrator->run();

			$this->assertSame([$migration->id()], $result->ran);
			$this->assertSame([], $statements);
			$this->assertSame($expected, $table->query()->get());
			$this->assertSame([['migration' => $migration->id(), 'batch' => '1']], $migrationTable->query()->select('migration', 'batch')->get());
			$this->assertTrue($migrator->status()[0]->isApplied());

			$next = $migrator->run();

			$this->assertSame([], $next->ran);
			$this->assertSame([$migration->id()], $next->skipped);
			$this->assertCount(1, $migrationTable->query()->get());
			$this->assertSame([], $statements);
			$this->assertSame($expected, $table->query()->get());
		} finally {
			remove_filter('query', $recordDdl);

			foreach ([$table, $migrationTable, $lockTable] as $createdTable) {
				$database->execute('DROP TABLE IF EXISTS %i', $createdTable->name());
			}
		}
	}
}
