<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\WPUnit\Database;

use Adbar\Dot;
use lucatume\DI52\Container as DI52Container;
use RuntimeException;
use StellarWP\ContainerContract\ContainerInterface;
use StellarWP\Foundation\Container\ContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Database\Contracts\Database as DatabaseContract;
use StellarWP\Foundation\Database\Contracts\Repository as MigrationRecordRepositoryContract;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Database;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\QueryException;
use StellarWP\Foundation\Database\Lock\DatabaseLock;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Migration\Repository;
use StellarWP\Foundation\Database\Schema;
use StellarWP\Foundation\Database\Schema\DbDelta;
use StellarWP\Foundation\Database\Schema\Reconciler;
use StellarWP\Foundation\Database\Scope\SiteScope;
use StellarWP\Foundation\Database\Table\TableDefinition;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DateTimePrecisionTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\IndexReconciliationTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\SchemaReconciliationTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestTable;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;

final class DatabaseIntegrationTest extends WPTestCase
{
	private Database $database;

	private Schema $schema;

	/**
	 * @var list<string>
	 */
	private array $tables = [];

	/**
	 * @throws RuntimeException When WordPress is not loaded.
	 */
	protected function setUp(): void {
		parent::setUp();

		if (! defined('ABSPATH')) {
			throw new RuntimeException('WordPress must be loaded before running database integration tests.');
		}

		$this->database = new Database($GLOBALS['wpdb'], new SiteScope($GLOBALS['wpdb']));
		$this->schema   = new Schema($this->database, new Reconciler($this->database, new DbDelta()));
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
		$this->assertSame($GLOBALS['wpdb']->prefix . 'wp_reports', $this->database->tableName('wp_reports'));
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
		], $this->database->table(new TestTable('database_table', $table))->select('name')->where('id', '=', 1)->get());
	}

	public function test_database_rejects_table_names_beyond_the_mysql_identifier_limit(): void {
		$maximum = str_repeat('a', 64 - strlen($GLOBALS['wpdb']->prefix));

		$this->assertSame($GLOBALS['wpdb']->prefix . $maximum, $this->database->tableName($maximum));

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('64-character identifier limit');

		$this->database->tableName(str_repeat('a', 65 - strlen($GLOBALS['wpdb']->prefix)));
	}

	public function test_schema_rejects_table_objects_beyond_the_mysql_identifier_limit(): void {
		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('64-character identifier limit');

		$this->schema->createOrUpdate(new TestTable('too_long', str_repeat('a', 65)));
	}

	public function test_database_crud_helpers_and_schema_inspection_use_wordpress(): void {
		$table       = $this->table('crud');
		$tableObject = new TestTable('crud_table', $table);

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

		$this->assertTrue($this->database->tableExists($tableObject));
		$this->assertTrue($this->database->columnExists($tableObject, 'status'));
		$this->assertTrue($this->database->indexExists($tableObject, 'status'));
		$this->assertFalse($this->database->columnExists($tableObject, 'missing'));
		$this->assertFalse($this->database->indexExists($tableObject, 'missing'));

		$id = $this->database->insertGetId($tableObject, [
			'name'   => 'draft report',
			'status' => 'draft',
		]);

		$this->assertGreaterThan(0, $id);
		$this->assertSame('draft', $this->database->value('SELECT status FROM %i WHERE id = %d', $table, $id));
		$this->assertSame(1, $this->database->update($tableObject, ['status' => 'published'], ['id' => $id]));
		$this->assertSame('published', $this->database->value('SELECT status FROM %i WHERE id = %d', $table, $id));
		$this->assertSame(1, $this->database->delete($tableObject, ['id' => $id]));
		$this->assertSame('0', (string) $this->database->value('SELECT COUNT(*) FROM %i', $table));
	}

	public function test_database_insert_returns_affected_rows_for_string_identifiers(): void {
		$table = $this->table('string_ids');

		$this->database->execute(sprintf(
			'CREATE TABLE %s (
				id varchar(26) NOT NULL,
				name varchar(191) NOT NULL,
				PRIMARY KEY  (id)
			) %s',
			$this->database->quoteIdentifier($table),
			$this->database->charsetCollate()
		));

		$this->assertSame(1, $this->database->insert(new TestTable('string_ids_table', $table), [
			'id'   => '01J2Z3Y4X5W6V7T8S9R0Q1P2N3',
			'name' => 'report',
		]));
	}

	public function test_database_returns_empty_results_without_query_errors(): void {
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
		$this->assertSame([], $this->database->rows('SELECT name FROM %i WHERE id = %d', $table, 999));
	}

	public function test_database_rejects_blank_sql(): void {
		$this->expectException(QueryException::class);
		$this->expectExceptionMessage('SQL statement cannot be empty.');

		$this->database->prepare(' ');
	}

	public function test_database_wraps_wordpress_query_failures(): void {
		$previous = $GLOBALS['wpdb']->suppress_errors(true);

		try {
			$exception = $this->assertQueryFails(fn (): mixed => $this->database->rows('SELECT * FROM %i', 'missing_foundation_table'));

			$this->assertSame('SELECT * FROM %i', $exception->sql());
			$this->assertSame(['missing_foundation_table'], $exception->bindings());
			$this->assertNotNull($exception->databaseError());

			$this->assertQueryFails(fn (): mixed => $this->database->row('SELECT * FROM %i', 'missing_foundation_table'));
			$this->assertQueryFails(fn (): mixed => $this->database->execute('SELECT * FROM %i', 'missing_foundation_table'));
			$this->assertQueryFails(fn (): mixed => $this->database->insert('missing_foundation_table', ['name' => 'test']));
			$this->assertQueryFails(fn (): mixed => $this->database->update('missing_foundation_table', ['name' => 'updated'], ['id' => 1]));
			$this->assertQueryFails(fn (): mixed => $this->database->delete('missing_foundation_table', ['id' => 1]));
		} finally {
			$GLOBALS['wpdb']->suppress_errors($previous);
		}
	}

	public function test_db_delta_reports_query_failures(): void {
		$table    = $this->table('invalid_schema');
		$previous = $GLOBALS['wpdb']->suppress_errors(true);

		try {
			$this->assertQueryFails(function () use ($table): void {
				(new DbDelta())->execute(sprintf(
					'CREATE TABLE %s (
						id definitely_invalid NOT NULL,
						PRIMARY KEY  (id)
					) %s;',
					$this->database->quoteIdentifier($table),
					$this->database->charsetCollate()
				));
			});
		} finally {
			$GLOBALS['wpdb']->suppress_errors($previous);
		}
	}

	public function test_schema_creates_inspects_and_changes_tables_through_wordpress(): void {
		$table       = $this->table('schema');
		$tableObject = new TestTable('schema_table', $table);
		$schema      = $this->schema;

		$schema->createOrUpdate($tableObject);
		$schema->execute(sprintf(
			'ALTER TABLE %s ADD KEY %s (%s)',
			$this->database->quoteIdentifier($table),
			$this->database->quoteIdentifier('name'),
			$this->database->quoteIdentifier('id')
		));
		$this->assertTrue($schema->hasTable($tableObject));
		$this->assertTrue($schema->hasIndex($tableObject, 'name'));

		$schema->dropIndex($tableObject, 'name');

		$this->assertFalse($schema->hasIndex($tableObject, 'name'));

		$schema->execute(sprintf(
			'DROP TABLE IF EXISTS %s',
			$this->database->quoteIdentifier($table)
		));

		$this->assertFalse($schema->hasTable($tableObject));
	}

	public function test_schema_creates_queue_style_table_definitions_through_wordpress(): void {
		$table  = $this->logicalTable('queue_schema');
		$schema = $this->schema;
		$queue  = new class($this->database, $table) implements Table {
			public function __construct(
				private DatabaseContract $database,
				private string $table
			) {
			}

			public function id(): string {
				return 'queue_schema_table';
			}

			public function name(): string {
				return $this->database->tableName($this->table);
			}

			public function definition(): TableDefinition {
				return TableDefinition::for($this)
					->bigIncrements('id')
					->string('queue', 255)
					->string('task_handler', 255)
					->longText('args')
					->integer('priority', 3)->nullable()
					->dateTime('run_after')->default('0000-00-00 00:00:00')
					->integer('taken')->default(0)
					->integer('done')->nullable()->default(0)
					->tinyInteger('tries')->unsigned()->default(0)
					->tinyInteger('failed', 1)->unsigned()->default(false)
					->index('done', 'done')
					->index('taken_failed', 'taken', 'failed')
					->index('taken_failed_done', 'taken', 'failed', 'done');
			}
		};

		$schema->createOrUpdate($queue);

		$this->assertTrue($schema->hasTable($queue));
		$this->assertTrue($this->database->columnExists($queue, 'args'));
		$this->assertTrue($this->database->columnExists($queue, 'priority'));
		$this->assertTrue($this->database->columnExists($queue, 'failed'));
		$this->assertTrue($schema->hasIndex($queue, 'taken_failed'));
		$this->assertTrue($schema->hasIndex($queue, 'taken_failed_done'));
	}

	public function test_datetime_zero_precision_is_canonical_and_idempotent(): void {
		$table = new DateTimePrecisionTable($this->table('datetime_zero'));

		$this->schema->createOrUpdate($table);
		$this->schema->createOrUpdate($table);

		$column = $this->database->row('SHOW COLUMNS FROM %i WHERE Field = %s', $table->name(), 'occurred_at');

		$this->assertSame('datetime', strtolower((string) ($column['Type'] ?? '')));
	}

	public function test_schema_preserves_quote_and_backslash_string_defaults(): void {
		$tableName = $this->table('string_default');
		$default   = "customer's \\ path";
		$table     = static function (string $columnDefault) use ($tableName): Table {
			return new class($tableName, $columnDefault) implements Table {
				public function __construct(
					private string $table,
					private string $default
				) {
				}

				public function id(): string {
					return 'string_default_table';
				}

				public function name(): string {
					return $this->table;
				}

				public function definition(): TableDefinition {
					return TableDefinition::for($this)
						->bigIncrements('id')
						->string('label', 100)->default($this->default);
				}
			};
		};

		$this->schema->createOrUpdate($table('initial'));
		$this->schema->createOrUpdate($table($default));
		$this->schema->createOrUpdate($table($default));
		$this->database->execute('INSERT INTO %i () VALUES ()', $tableName);

		$this->assertSame($default, $this->database->value('SELECT label FROM %i LIMIT 1', $tableName));
	}

	public function test_schema_rejects_unapplied_numeric_defaults_and_nullability(): void {
		$table = $this->table('column_properties');

		$this->schema->createOrUpdate(new SchemaReconciliationTable($table, 1, false));

		try {
			$this->schema->createOrUpdate(new SchemaReconciliationTable($table, 5, true));
			$this->fail('Expected unapplied column properties to fail schema reconciliation.');
		} catch (DatabaseException $exception) {
			$this->assertStringContainsString('column attempts expected DEFAULT 5, found DEFAULT 1', $exception->getMessage());
			$this->assertStringContainsString('column completed_at expected NULL, found NOT NULL', $exception->getMessage());
		}

		$this->database->execute(
			'ALTER TABLE %i MODIFY COLUMN attempts int(10) NOT NULL DEFAULT 5, MODIFY COLUMN completed_at datetime NULL',
			$table
		);
		$this->schema->createOrUpdate(new SchemaReconciliationTable($table, 5, true));
		$this->database->execute('INSERT INTO %i (completed_at) VALUES (NULL)', $table);

		$row = $this->database->row('SELECT attempts, completed_at FROM %i LIMIT 1', $table);

		$this->assertSame('5', $row['attempts'] ?? null);
		$this->assertNull($row['completed_at'] ?? null);
	}

	public function test_schema_rejects_an_index_that_db_delta_does_not_remove(): void {
		$table      = $this->table('removed_index');
		$definition = new IndexReconciliationTable($table, true);

		$this->schema->createOrUpdate($definition);
		$this->assertTrue($this->schema->hasIndex($definition, 'email_unique'));

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('unexpected index email_unique');

		$this->schema->createOrUpdate(new IndexReconciliationTable($table, false));
	}

	public function test_schema_rejects_an_unapplied_auto_increment_attribute(): void {
		$table = $this->table('column_extra');

		$this->database->execute(sprintf(
			'CREATE TABLE %s (id bigint(20) unsigned NOT NULL, PRIMARY KEY (id)) %s',
			$this->database->quoteIdentifier($table),
			$this->database->charsetCollate()
		));

		try {
			$this->schema->createOrUpdate(new TestTable('column_extra', $table));
			$this->fail('Expected an unapplied AUTO_INCREMENT attribute to fail schema reconciliation.');
		} catch (DatabaseException $exception) {
			$this->assertStringContainsString('column id expected extra auto_increment, found none', $exception->getMessage());
		}

		$this->database->execute('ALTER TABLE %i MODIFY COLUMN id bigint(20) unsigned NOT NULL AUTO_INCREMENT', $table);
		$this->schema->createOrUpdate(new TestTable('column_extra', $table));
	}

	public function test_migration_repository_persists_records_in_wordpress(): void {
		$tableName      = $this->logicalTable('migrations');
		$table          = $this->database->tableName($tableName);
		$schema         = $this->schema;
		$migrationTable = new MigrationTable($tableName, $this->database);
		$repository     = new Repository($migrationTable);

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

		$repository->recordRun('CreateReports', 2);
		$repository->recordRun('createreports', 2);

		$this->assertTrue($repository->hasRun('CreateReports'));
		$this->assertTrue($repository->hasRun('createreports'));
		$this->assertCount(2, $repository->recordsForBatch(2));

		$schema->drop($migrationTable);

		$this->assertFalse($schema->hasTable($migrationTable));
	}

	public function test_database_lock_coordinates_ownership_in_wordpress(): void {
		$tableName = $this->logicalTable('locks');
		$table     = $this->database->tableName($tableName);
		$wpSchema  = $this->schema;
		$lockTable = new LockTable($tableName, $this->database);
		$lock      = new DatabaseLock($this->database, $lockTable);

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

	public function test_database_lock_replaces_expired_ownership_without_allowing_the_previous_owner_to_release_it(): void {
		$tableName = $this->logicalTable('expired_locks');
		$table     = $this->database->tableName($tableName);
		$wpSchema  = $this->schema;
		$lockTable = new LockTable($tableName, $this->database);
		$lock      = new DatabaseLock($this->database, $lockTable);

		$wpSchema->createOrUpdate($lockTable);

		$first = $lock->acquire('foundation:database:takeover', 60);

		$this->assertNotNull($first);

		$this->database->execute(
			'UPDATE %i SET expires_at = TIMESTAMPADD(SECOND, -1, UTC_TIMESTAMP(6)) WHERE name = %s',
			$table,
			'foundation:database:takeover'
		);

		$second = $lock->acquire('foundation:database:takeover', 60);

		$this->assertNotNull($second);
		$this->assertNotSame($first->owner, $second->owner);
		$this->assertFalse($lock->release($first));
		$this->assertTrue($lock->isAcquired('foundation:database:takeover'));
		$this->assertTrue($lock->release($second));

		$wpSchema->drop($lockTable);
	}

	public function test_database_lock_compares_names_and_owners_by_exact_bytes(): void {
		$tableName = $this->logicalTable('exact_locks');
		$table     = $this->database->tableName($tableName);
		$wpSchema  = $this->schema;
		$lockTable = new LockTable($tableName, $this->database);
		$lock      = new DatabaseLock($this->database, $lockTable);

		$wpSchema->createOrUpdate($lockTable);

		$upper = $lock->acquire('Catalog:1', 60);
		$lower = $lock->acquire('catalog:1', 60);

		$this->assertNotNull($upper);
		$this->assertNotNull($lower);

		$this->database->execute(
			'UPDATE %i SET owner = %s WHERE name = %s',
			$table,
			'owner',
			$lower->name
		);

		$this->assertFalse($lock->release(new LockToken($lower->name, 'OWNER', $lower->expiresAt)));
		$this->assertTrue($lock->release(new LockToken($lower->name, 'owner', $lower->expiresAt)));
		$this->assertTrue($lock->release($upper));

		$wpSchema->drop($lockTable);
	}

	public function test_lock_table_reconciles_an_existing_previous_definition(): void {
		$tableName = $this->logicalTable('previous_lock_schema');
		$table     = $this->database->tableName($tableName);
		$wpSchema  = $this->schema;
		$lockTable = new LockTable($tableName, $this->database);

		$this->database->execute(sprintf(
			'CREATE TABLE %s (
				name varchar(191) NOT NULL,
				owner varchar(64) NOT NULL,
				expires_at datetime NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (name),
				KEY expires_at (expires_at)
			) %s',
			$this->database->quoteIdentifier($table),
			$this->database->charsetCollate()
		));

		$wpSchema->createOrUpdate($lockTable);

		$name       = $this->database->row('SHOW COLUMNS FROM %i WHERE Field = %s', $table, 'name');
		$owner      = $this->database->row('SHOW COLUMNS FROM %i WHERE Field = %s', $table, 'owner');
		$expiration = $this->database->row('SHOW COLUMNS FROM %i WHERE Field = %s', $table, 'expires_at');

		$this->assertSame('varbinary(191)', strtolower((string) ($name['Type'] ?? '')));
		$this->assertSame('varbinary(64)', strtolower((string) ($owner['Type'] ?? '')));
		$this->assertSame('datetime(6)', strtolower((string) ($expiration['Type'] ?? '')));

		$wpSchema->drop($lockTable);
	}

	public function test_migration_table_reconciles_case_insensitive_identifiers(): void {
		$tableName      = $this->logicalTable('previous_migration_schema');
		$table          = $this->database->tableName($tableName);
		$wpSchema       = $this->schema;
		$migrationTable = new MigrationTable($tableName, $this->database);

		$this->database->execute(sprintf(
			'CREATE TABLE %s (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				migration varchar(191) NOT NULL,
				batch int(10) unsigned NOT NULL,
				ran_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY migration (migration),
				KEY batch (batch)
			) %s',
			$this->database->quoteIdentifier($table),
			$this->database->charsetCollate()
		));

		$wpSchema->createOrUpdate($migrationTable);

		$migration = $this->database->row('SHOW COLUMNS FROM %i WHERE Field = %s', $table, 'migration');

		$this->assertSame('varbinary(191)', strtolower((string) ($migration['Type'] ?? '')));

		$wpSchema->drop($migrationTable);
	}

	public function test_provider_registers_wordpress_prefixed_database_services(): void {
		$container = $this->newContainer();

		$container->register(DatabaseProvider::class);

		$this->assertSame('nx_foundation_migrations', $container->get(DatabaseProvider::MIGRATIONS_TABLE));
		$this->assertSame('nx_foundation_locks', $container->get(DatabaseProvider::LOCKS_TABLE));
		$this->assertInstanceOf(Database::class, $container->get(Database::class));
		$this->assertInstanceOf(Database::class, $container->get(DatabaseContract::class));
		$this->assertInstanceOf(Schema::class, $container->get(Schema::class));
		$this->assertSame($GLOBALS['wpdb']->prefix . 'nx_foundation_migrations', $container->get(MigrationTable::class)->name());
		$this->assertSame($GLOBALS['wpdb']->prefix . 'nx_foundation_locks', $container->get(LockTable::class)->name());
		$this->assertInstanceOf(Repository::class, $container->get(MigrationRecordRepositoryContract::class));
		$this->assertInstanceOf(Migrator::class, $container->get(Migrator::class));
		$this->assertFalse($container->has(Lock::class));
	}

	private function table(string $suffix): string {
		return $this->database->tableName($this->logicalTable($suffix));
	}

	private function logicalTable(string $suffix): string {
		$table = 'foundation_' . $suffix . '_' . str_replace('.', '_', uniqid('', true));

		$this->tables[] = $this->database->tableName($table);

		return $table;
	}

	/**
	 * @param callable(): mixed $callback
	 */
	private function assertQueryFails(callable $callback): QueryException {
		try {
			$callback();
		} catch (QueryException $exception) {
			$this->assertNotSame('', $exception->getMessage());

			return $exception;
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
