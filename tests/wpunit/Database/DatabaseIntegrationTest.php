<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\WPUnit\Database;

use Adbar\Dot;
use lucatume\DI52\Container as DI52Container;
use StellarWP\ContainerContract\ContainerInterface;
use StellarWP\Foundation\Container\ContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Database\Contracts\Database as DatabaseContract;
use StellarWP\Foundation\Database\Contracts\Repository as MigrationRecordRepositoryContract;
use StellarWP\Foundation\Database\Database;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Database\Exceptions\QueryException;
use StellarWP\Foundation\Database\Lock\DatabaseLock;
use StellarWP\Foundation\Database\Migration\Repository;
use StellarWP\Foundation\Database\Migration\Runner;
use StellarWP\Foundation\Database\Schema;
use StellarWP\Foundation\Database\Table\Collection as TableCollection;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;

final class DatabaseIntegrationTest extends WPTestCase
{
	private Database $database;

	/**
	 * @var list<string>
	 */
	private array $tables = [];

	protected function setUp(): void {
		parent::setUp();

		$this->database = new Database($GLOBALS['wpdb']);
	}

	protected function tearDown(): void {
		foreach (array_reverse($this->tables) as $table) {
			$this->database->execute(sprintf(
				'DROP TABLE IF EXISTS %s',
				$this->database->quoteIdentifier($table)
			));
		}

		parent::tearDown();
	}

	public function test_database_executes_and_reads_rows_through_wpdb(): void {
		$table = $this->table('database');

		$this->database->execute(sprintf(
			'CREATE TABLE %s (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				PRIMARY KEY  (id)
			) %s',
			$this->database->quoteIdentifier($table),
			$this->database->charsetCollate()
		));

		$this->database->execute(
			'INSERT INTO %i (name) VALUES (%s), (%s)',
			$table,
			'first',
			'second'
		);

		$this->assertSame($GLOBALS['wpdb']->prefix . 'example', $this->database->tableName('example'));
		$this->assertSame(['name' => 'first'], $this->database->row(sprintf(
			'SELECT name FROM %s WHERE id = 1',
			$this->database->quoteIdentifier($table)
		)));
		$this->assertSame([
			['name' => 'first'],
			['name' => 'second'],
		], $this->database->rows(sprintf(
			'SELECT name FROM %s ORDER BY id ASC',
			$this->database->quoteIdentifier($table)
		)));
		$this->assertSame([
			['name' => 'first'],
		], $this->database->table($table)->select('name')->where('id', '=', 1)->get());
	}

	public function test_database_crud_helpers_and_schema_inspection_use_wordpress(): void {
		$table = $this->table('crud');

		$this->database->execute(sprintf(
			'CREATE TABLE %s (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				status varchar(20) NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status)
			) %s',
			$this->database->quoteIdentifier($table),
			$this->database->charsetCollate()
		));

		$this->assertTrue($this->database->tableExists($table));
		$this->assertTrue($this->database->columnExists($table, 'status'));
		$this->assertTrue($this->database->indexExists($table, 'status'));
		$this->assertFalse($this->database->columnExists($table, 'missing'));
		$this->assertFalse($this->database->indexExists($table, 'missing'));

		$id = $this->database->insert($table, [
			'name'   => 'draft report',
			'status' => 'draft',
		]);

		$this->assertGreaterThan(0, $id);
		$this->assertSame('draft', $this->database->value('SELECT status FROM %i WHERE id = %d', $table, $id));
		$this->assertSame(1, $this->database->update($table, ['status' => 'published'], ['id' => $id]));
		$this->assertSame('published', $this->database->value('SELECT status FROM %i WHERE id = %d', $table, $id));
		$this->assertSame(1, $this->database->delete($table, ['id' => $id]));
		$this->assertSame('0', (string) $this->database->value('SELECT COUNT(*) FROM %i', $table));
	}

	public function test_database_returns_null_for_missing_values_without_query_errors(): void {
		$table = $this->table('missing_value');

		$this->database->execute(sprintf(
			'CREATE TABLE %s (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				PRIMARY KEY  (id)
			) %s',
			$this->database->quoteIdentifier($table),
			$this->database->charsetCollate()
		));

		$this->assertNull($this->database->value('SELECT name FROM %i WHERE id = %d', $table, 999));
	}

	public function test_database_wraps_wordpress_query_failures(): void {
		$previous = $GLOBALS['wpdb']->suppress_errors(true);

		try {
			$this->assertQueryFails(fn (): mixed => $this->database->row('SELECT * FROM %i', 'missing_foundation_table'));
			$this->assertSame([], $this->database->rows('SELECT * FROM %i', 'missing_foundation_table'));
			$this->assertQueryFails(fn (): mixed => $this->database->execute('SELECT * FROM %i', 'missing_foundation_table'));
			$this->assertQueryFails(fn (): mixed => $this->database->insert('missing_foundation_table', ['name' => 'test']));
			$this->assertQueryFails(fn (): mixed => $this->database->update('missing_foundation_table', ['name' => 'updated'], ['id' => 1]));
			$this->assertQueryFails(fn (): mixed => $this->database->delete('missing_foundation_table', ['id' => 1]));
		} finally {
			$GLOBALS['wpdb']->suppress_errors($previous);
		}
	}

	public function test_schema_creates_inspects_and_changes_tables_through_wordpress(): void {
		$table  = $this->table('schema');
		$schema = new Schema($this->database);

		$schema->createOrUpdate(sprintf(
			'CREATE TABLE %s (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				PRIMARY KEY  (id),
				KEY name (name)
			) %s;',
			$this->database->quoteIdentifier($table),
			$this->database->charsetCollate()
		));

		$this->assertTrue($schema->hasTable($table));
		$this->assertTrue($schema->hasIndex($table, 'name'));

		$schema->dropIndex($table, 'name');

		$this->assertFalse($schema->hasIndex($table, 'name'));

		$schema->execute(sprintf(
			'DROP TABLE IF EXISTS %s',
			$this->database->quoteIdentifier($table)
		));

		$this->assertFalse($schema->hasTable($table));
	}

	public function test_migration_repository_persists_records_in_wordpress(): void {
		$table          = $this->table('migrations');
		$schema         = new Schema($this->database);
		$migrationTable = new MigrationTable($this->database, $table);
		$repository     = new Repository($this->database, $table);

		$this->assertFalse($schema->hasTable($migrationTable));

		$schema->createOrUpdate($migrationTable);

		$this->assertTrue($schema->hasTable($migrationTable));
		$this->assertSame($table, $migrationTable->name());
		$this->assertSame(1, $repository->nextBatch());

		$record = $repository->recordRun('2026_06_23_000001_create_example_table', 1);

		$this->assertGreaterThan(0, $record->id);
		$this->assertTrue($repository->hasRun('2026_06_23_000001_create_example_table'));
		$this->assertSame(2, $repository->nextBatch());
		$this->assertSame(1, $repository->latestBatch());
		$this->assertArrayHasKey('2026_06_23_000001_create_example_table', $repository->all());
		$this->assertCount(1, $repository->recordsForBatch(1));
		$this->assertTrue($repository->deleteRun('2026_06_23_000001_create_example_table'));
		$this->assertFalse($repository->hasRun('2026_06_23_000001_create_example_table'));

		$schema->drop($migrationTable);

		$this->assertFalse($schema->hasTable($migrationTable));
	}

	public function test_database_lock_coordinates_ownership_in_wordpress(): void {
		$table     = $this->table('locks');
		$wpSchema  = new Schema($this->database);
		$lockTable = new LockTable($this->database, $table);
		$lock      = new DatabaseLock($this->database, $table);

		$this->assertFalse($wpSchema->hasTable($lockTable));

		$wpSchema->createOrUpdate($lockTable);

		$this->assertTrue($wpSchema->hasTable($lockTable));
		$this->assertSame($table, $lockTable->name());

		$token = $lock->acquire('foundation:database:test', 60);

		$this->assertNotNull($token);
		$this->assertNull($lock->acquire('foundation:database:test', 60));
		$this->assertTrue($lock->isAcquired('foundation:database:test'));
		$this->assertNotNull($lock->refresh($token, 120));
		$this->assertTrue($lock->release($token));
		$this->assertFalse($lock->isAcquired('foundation:database:test'));

		$wpSchema->drop($lockTable);

		$this->assertFalse($wpSchema->hasTable($lockTable));
	}

	public function test_provider_registers_wordpress_prefixed_database_services(): void {
		$container = $this->newContainer();

		$container->register(DatabaseProvider::class);

		$this->assertSame($GLOBALS['wpdb']->prefix . 'nexcess_foundation_migrations', $container->get(DatabaseProvider::MIGRATIONS_TABLE));
		$this->assertSame($GLOBALS['wpdb']->prefix . 'nexcess_foundation_locks', $container->get(DatabaseProvider::LOCKS_TABLE));
		$this->assertInstanceOf(Database::class, $container->get(Database::class));
		$this->assertInstanceOf(Database::class, $container->get(DatabaseContract::class));
		$this->assertInstanceOf(Schema::class, $container->get(Schema::class));
		$this->assertInstanceOf(TableCollection::class, $container->get(TableCollection::class));
		$this->assertInstanceOf(MigrationTable::class, $container->get(MigrationTable::class));
		$this->assertInstanceOf(LockTable::class, $container->get(LockTable::class));
		$this->assertInstanceOf(Repository::class, $container->get(MigrationRecordRepositoryContract::class));
		$this->assertInstanceOf(Runner::class, $container->get(Runner::class));
		$this->assertFalse($container->has(Lock::class));
	}

	private function table(string $suffix): string {
		$table = $GLOBALS['wpdb']->prefix . 'foundation_' . $suffix . '_' . str_replace('.', '_', uniqid('', true));

		$this->tables[] = $table;

		return $table;
	}

	/**
	 * @param callable(): mixed $callback
	 */
	private function assertQueryFails(callable $callback): void {
		try {
			$callback();
		} catch (QueryException $exception) {
			$this->assertNotSame('', $exception->getMessage());

			return;
		}

		$this->fail('Expected the database operation to throw a query exception.');
	}

	private function newContainer(): Container {
		$container = new ContainerAdapter(new DI52Container());
		$container->bind(Container::class, $container);
		$container->bind(ContainerInterface::class, $container);
		$container->singleton(Dot::class, new Dot());

		return $container;
	}
}
