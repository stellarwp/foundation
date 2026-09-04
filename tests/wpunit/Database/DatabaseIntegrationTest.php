<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\WPUnit\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Database\Contracts\Database as DatabaseContract;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Database;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\QueryException;
use StellarWP\Foundation\Database\Lock\DatabaseLock;
use StellarWP\Foundation\Database\Migration\Contracts\Repository as MigrationRecordRepositoryContract;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Migration\Repository;
use StellarWP\Foundation\Database\Migration\StoreSchema;
use StellarWP\Foundation\Database\Migration\ValueObjects\Record;
use StellarWP\Foundation\Database\Query\QueryBuilder;
use StellarWP\Foundation\Database\Schema;
use StellarWP\Foundation\Database\Schema\DbDelta;
use StellarWP\Foundation\Database\Schema\Editor;
use StellarWP\Foundation\Database\Schema\Reconciler;
use StellarWP\Foundation\Database\Scope\SiteScope;
use StellarWP\Foundation\Database\Table\Blueprint;
use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\CommentedTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\CommentReconciliationTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DateTimePrecisionTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\IndexReconciliationTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\SchemaReconciliationTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestDatabaseTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestTable;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;

final class DatabaseIntegrationTest extends WPTestCase
{
	private Database $database;

	private Schema $schema;

	private Reconciler $reconciler;

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

		$this->database   = new Database($GLOBALS['wpdb'], new SiteScope($GLOBALS['wpdb']));
		$this->reconciler = new Reconciler($this->database, new DbDelta());
		$this->schema     = new Schema($this->database, $this->reconciler, new Editor($this->database, $this->reconciler));
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
		$tableObject = new TestTable($this->unprefixedTable('database'));
		$table       = $this->database->tableName($tableObject);

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

		$this->assertSame($GLOBALS['wpdb']->prefix . 'example', $this->database->tableName(new TestTable('example')));
		$this->assertSame($GLOBALS['wpdb']->prefix . 'wp_reports', $this->database->tableName(new TestTable('wp_reports')));
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
		], (new QueryBuilder($this->database, $tableObject))->select('name')->where('id', '=', 1)->get());
	}

	public function test_database_rejects_table_names_beyond_the_mysql_identifier_limit(): void {
		$maximum = str_repeat('a', 64 - strlen($GLOBALS['wpdb']->prefix));

		$this->assertSame(
			$GLOBALS['wpdb']->prefix . $maximum,
			$this->database->tableName(new TestTable($maximum))
		);

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('64-character identifier limit');

		$this->database->tableName(new TestTable(str_repeat('a', 65 - strlen($GLOBALS['wpdb']->prefix))));
	}

	/**
	 * @dataProvider invalidTableNameProvider
	 */
	#[DataProvider('invalidTableNameProvider')]
	public function test_database_rejects_invalid_unprefixed_table_names(string $tableName, string $message): void {
		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage($message);

		$this->database->tableName(new TestTable($tableName));
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function invalidTableNameProvider(): array {
		return [
			'empty'          => ['', 'cannot be blank or contain surrounding whitespace'],
			'padded'         => [' reports ', 'cannot be blank or contain surrounding whitespace'],
			'unsafe quoting' => ['reports`archive', 'may contain only ASCII letters, numbers, and underscores'],
		];
	}

	public function test_schema_rejects_table_objects_beyond_the_mysql_identifier_limit(): void {
		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('64-character identifier limit');

		$this->schema->create((new TestDatabaseTable(str_repeat('a', 65), $this->database))->blueprint());
	}

	public function test_database_crud_helpers_and_schema_inspection_use_wordpress(): void {
		$tableObject = new TestTable($this->unprefixedTable('crud'));
		$table       = $this->database->tableName($tableObject);

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
		$tableObject = new TestTable($this->unprefixedTable('string_ids'));
		$table       = $this->database->tableName($tableObject);

		$this->database->execute(sprintf(
			'CREATE TABLE %s (
				id varchar(26) NOT NULL,
				name varchar(191) NOT NULL,
				PRIMARY KEY  (id)
			) %s',
			$this->database->quoteIdentifier($table),
			$this->database->charsetCollate()
		));

		$this->assertSame(1, $this->database->insert($tableObject, [
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

	public function test_database_quotes_identifiers_and_escapes_embedded_backticks(): void {
		$this->assertSame('`report``status`', $this->database->quoteIdentifier('report`status'));
	}

	public function test_prepare_failure_does_not_report_an_unrelated_database_error(): void {
		$previousError               = $GLOBALS['wpdb']->last_error;
		$GLOBALS['wpdb']->last_error = 'Previous query failed.';
		$this->setExpectedIncorrectUsage('wpdb::prepare');

		try {
			$this->database->prepare('SELECT %s, %s', 'first');
			$this->fail('Expected SQL preparation to fail.');
		} catch (QueryException $exception) {
			$this->assertNull($exception->databaseError());
		} finally {
			$GLOBALS['wpdb']->last_error = $previousError;
		}
	}

	public function test_database_wraps_wordpress_query_failures(): void {
		$previous = $GLOBALS['wpdb']->suppress_errors(true);
		$table    = new TestTable('missing_foundation_table');

		try {
			$exception = $this->assertQueryFails(fn (): mixed => $this->database->rows('SELECT * FROM %i', 'missing_foundation_table'));

			$this->assertSame('SELECT * FROM %i', $exception->sql());
			$this->assertSame(['missing_foundation_table'], $exception->bindings());
			$this->assertNotNull($exception->databaseError());

			$this->assertQueryFails(fn (): mixed => $this->database->row('SELECT * FROM %i', 'missing_foundation_table'));
			$this->assertQueryFails(fn (): mixed => $this->database->execute('SELECT * FROM %i', 'missing_foundation_table'));

			$tableName = $this->database->tableName($table);

			$this->assertSame(
				sprintf('INSERT INTO `%s`', $tableName),
				$this->assertQueryFails(fn (): mixed => $this->database->insert($table, ['name' => 'test']))->sql()
			);
			$this->assertSame(
				sprintf('UPDATE `%s`', $tableName),
				$this->assertQueryFails(fn (): mixed => $this->database->update($table, ['name' => 'updated'], ['id' => 1]))->sql()
			);
			$this->assertSame(
				sprintf('DELETE FROM `%s`', $tableName),
				$this->assertQueryFails(fn (): mixed => $this->database->delete($table, ['id' => 1]))->sql()
			);
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
		$tableObject = new TestTable($this->unprefixedTable('schema'));
		$table       = $this->database->tableName($tableObject);
		$schema      = $this->schema;
		$definition  = Blueprint::for($tableObject);
		$definition->bigIncrements('id');
		$definition->string('legacy_name');

		$schema->create($definition);
		$schema->execute(sprintf(
			'ALTER TABLE %s ADD KEY %s (%s)',
			$this->database->quoteIdentifier($table),
			$this->database->quoteIdentifier('name'),
			$this->database->quoteIdentifier('legacy_name')
		));
		$this->assertTrue($schema->hasTable($tableObject));
		$this->assertTrue($schema->hasIndex($tableObject, 'name'));
		$this->assertTrue($this->database->columnExists($tableObject, 'legacy_name'));

		$change = Blueprint::for($tableObject);
		$change->dropIndex('name');
		$change->dropColumn('legacy_name');
		$schema->alter($change);

		$this->assertFalse($schema->hasIndex($tableObject, 'name'));
		$this->assertFalse($this->database->columnExists($tableObject, 'legacy_name'));

		$schema->execute(sprintf(
			'DROP TABLE IF EXISTS %s',
			$this->database->quoteIdentifier($table)
		));

		$this->assertFalse($schema->hasTable($tableObject));
	}

	public function test_schema_creates_queue_style_table_definitions_through_wordpress(): void {
		$table  = $this->unprefixedTable('queue_schema');
		$schema = $this->schema;
		$queue  = new class($table) implements Table {
			public function __construct(
				private string $unprefixedName
			) {
			}

			public function unprefixedName(): string {
				return $this->unprefixedName;
			}
		};
		$definition = Blueprint::for($queue);

		$definition->bigIncrements('id');
		$definition->string('queue', 255);
		$definition->string('task_handler', 255);
		$definition->longText('args');
		$definition->integer('priority', 3)->nullable();
		$definition->dateTime('run_after')->default('0000-00-00 00:00:00');
		$definition->integer('taken')->default(0);
		$definition->integer('done')->nullable()->default(0);
		$definition->tinyInteger('tries')->unsigned()->default(0);
		$definition->tinyInteger('failed', 1)->unsigned()->default(false);
		$definition->index('done', 'done');
		$definition->index('taken_failed', 'taken', 'failed');
		$definition->index('taken_failed_done', 'taken', 'failed', 'done');

		$schema->create($definition);

		$this->assertTrue($schema->hasTable($queue));
		$this->assertTrue($this->database->columnExists($queue, 'args'));
		$this->assertTrue($this->database->columnExists($queue, 'priority'));
		$this->assertTrue($this->database->columnExists($queue, 'failed'));
		$this->assertTrue($schema->hasIndex($queue, 'taken_failed'));
		$this->assertTrue($schema->hasIndex($queue, 'taken_failed_done'));
	}

	public function test_schema_canonicalizes_supported_custom_type_spellings(): void {
		$table     = new TestTable($this->unprefixedTable('custom_types'));
		$blueprint = Blueprint::for($table);

		$blueprint->bigIncrements('id');
		$blueprint->column(new Column('measurement', 'DOUBLE PRECISION'));
		$blueprint->column(new Column('amount', 'decimal(10, 2)'));

		$this->schema->create($blueprint);

		$measurement = $this->database->row('SHOW FULL COLUMNS FROM %i WHERE Field = %s', $this->database->tableName($table), 'measurement');
		$amount      = $this->database->row('SHOW FULL COLUMNS FROM %i WHERE Field = %s', $this->database->tableName($table), 'amount');

		$this->assertSame('double', strtolower((string) ($measurement['Type'] ?? '')));
		$this->assertSame('decimal(10,2)', strtolower((string) ($amount['Type'] ?? '')));
	}

	public function test_schema_rejects_an_existing_table_with_an_incompatible_charset(): void {
		$table     = new TestTable($this->unprefixedTable('wrong_charset'));
		$tableName = $this->database->tableName($table);
		$blueprint = Blueprint::for($table);

		$blueprint->bigIncrements('id');
		$blueprint->string('label', 20);
		$this->database->execute(sprintf(
			'CREATE TABLE %s (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				label varchar(20) NOT NULL,
				PRIMARY KEY  (id)
			) DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci',
			$this->database->quoteIdentifier($tableName)
		));

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('table expected collation');
		$this->expectExceptionMessage('found latin1_swedish_ci');

		$this->schema->create($blueprint);
	}

	public function test_schema_rejects_an_existing_text_column_with_an_incompatible_charset(): void {
		$table     = new TestTable($this->unprefixedTable('wrong_column_charset'));
		$tableName = $this->database->tableName($table);
		$blueprint = Blueprint::for($table);

		$blueprint->bigIncrements('id');
		$blueprint->string('label', 20);
		$this->database->execute(sprintf(
			'CREATE TABLE %s (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				label varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
				PRIMARY KEY  (id)
			) %s',
			$this->database->quoteIdentifier($tableName),
			$this->database->charsetCollate()
		));

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('column label expected collation');
		$this->expectExceptionMessage('found latin1_swedish_ci');

		$this->schema->create($blueprint);
	}

	public function test_schema_does_not_reapply_an_old_creation_blueprint_to_an_evolved_table(): void {
		$table   = new TestTable($this->unprefixedTable('historical_create'));
		$initial = Blueprint::for($table);

		$initial->bigIncrements('id');
		$initial->string('status', 20);
		$this->schema->create($initial);

		$change = Blueprint::for($table);
		$change->string('status', 40)->change();
		$this->schema->alter($change);

		try {
			$this->schema->create($initial);
			$this->fail('Expected the historical creation blueprint to reject the evolved column.');
		} catch (DatabaseException $exception) {
			$this->assertStringContainsString('column status expected type varchar(20), found varchar(40)', $exception->getMessage());
		}

		$status = $this->database->row(
			'SHOW FULL COLUMNS FROM %i WHERE Field = %s',
			$this->database->tableName($table),
			'status'
		);

		$this->assertSame('varchar(40)', strtolower((string) ($status['Type'] ?? '')));
	}

	public function test_datetime_zero_precision_is_canonical_and_idempotent(): void {
		$table = new DateTimePrecisionTable($this->unprefixedTable('datetime_zero'));

		$this->schema->create($table->blueprint());
		$this->schema->create($table->blueprint());

		$column = $this->database->row(
			'SHOW COLUMNS FROM %i WHERE Field = %s',
			$this->database->tableName($table),
			'occurred_at'
		);

		$this->assertSame('datetime', strtolower((string) ($column['Type'] ?? '')));
	}

	public function test_schema_creates_and_verifies_column_comments_in_each_backslash_escaping_mode(): void {
		$originalSqlMode = (string) $this->database->value('SELECT @@SESSION.sql_mode');
		$sqlModes        = array_values(array_filter(explode(',', $originalSqlMode), static fn (string $mode): bool => $mode !== 'NO_BACKSLASH_ESCAPES'));
		$testModes       = [implode(',', $sqlModes), implode(',', [...$sqlModes, 'NO_BACKSLASH_ESCAPES'])];

		foreach ($testModes as $index => $sqlMode) {
			$this->database->execute('SET SESSION sql_mode = %s', $sqlMode);
			$activeSqlMode = (string) $this->database->value('SELECT @@SESSION.sql_mode');

			try {
				$unprefixedTableName = $this->unprefixedTable('column_comment_' . $index);
				$comment             = "Customer's updated description; internal metadata";
				$table               = new CommentedTable($unprefixedTableName, $comment);

				$this->schema->create((new CommentedTable($unprefixedTableName, null))->blueprint());
				$this->alterDescriptionComment($table, "Customer's description");
				$this->alterDescriptionComment($table, $comment);
				$this->alterDescriptionComment($table, $comment);

				$column = $this->database->row(
					'SHOW FULL COLUMNS FROM %i WHERE Field = %s',
					$this->database->tableName($table),
					'description'
				);

				$this->assertSame($activeSqlMode, $this->database->value('SELECT @@SESSION.sql_mode'));
				$this->assertSame($comment, $column['Comment'] ?? null);

				$this->alterDescriptionComment($table, null);

				$column = $this->database->row(
					'SHOW FULL COLUMNS FROM %i WHERE Field = %s',
					$this->database->tableName($table),
					'description'
				);

				$this->assertSame('', $column['Comment'] ?? null);
			} finally {
				$this->database->execute('SET SESSION sql_mode = %s', $originalSqlMode);
			}
		}

		$this->assertSame($originalSqlMode, $this->database->value('SELECT @@SESSION.sql_mode'));
	}

	public function test_schema_changes_and_removes_comments_while_preserving_supported_column_attributes(): void {
		$unprefixedTableName = $this->unprefixedTable('reconciled_comment');
		$table               = new CommentReconciliationTable($unprefixedTableName, null);

		$this->schema->create($table->blueprint());
		$this->alterReconciliationComments($table, 'Initial comment');
		$this->alterReconciliationComments($table, 'Updated comment');
		$this->alterReconciliationComments($table, 'Updated comment');

		$this->assertCommentReconciliationTable($unprefixedTableName, 'Updated comment');

		$this->alterReconciliationComments($table, null);
		$this->alterReconciliationComments($table, null);

		$this->assertCommentReconciliationTable($unprefixedTableName, '');
	}

	public function test_schema_preserves_quote_and_backslash_string_defaults(): void {
		$unprefixedTableName = $this->unprefixedTable('string_default');
		$tableObject         = new TestTable($unprefixedTableName);
		$tableName           = $this->database->tableName($tableObject);
		$default             = "customer's \\ path";
		$definition          = Blueprint::for($tableObject);
		$definition->bigIncrements('id');
		$definition->string('label', 100)->default('initial');
		$this->schema->create($definition);

		$change = Blueprint::for($tableObject);
		$change->string('label', 100)->default($default)->change();
		$this->schema->alter($change);
		$this->schema->alter($change);
		$this->database->execute('INSERT INTO %i () VALUES ()', $tableName);

		$this->assertSame($default, $this->database->value('SELECT label FROM %i LIMIT 1', $tableName));
	}

	public function test_schema_explicitly_changes_numeric_defaults_and_nullability(): void {
		$unprefixedTableName = $this->unprefixedTable('column_properties');
		$tableObject         = new SchemaReconciliationTable($unprefixedTableName, 1, false);
		$table               = $this->database->tableName($tableObject);

		$this->schema->create($tableObject->blueprint());

		$change = Blueprint::for($tableObject);
		$change->integer('attempts')->default(5)->change();
		$change->dateTime('completed_at')->nullable()->change();
		$this->schema->alter($change);
		$this->database->execute('INSERT INTO %i (completed_at) VALUES (NULL)', $table);

		$row = $this->database->row('SELECT attempts, completed_at FROM %i LIMIT 1', $table);

		$this->assertSame('5', $row['attempts'] ?? null);
		$this->assertNull($row['completed_at'] ?? null);
	}

	public function test_schema_removes_declared_indexes_without_rejecting_external_indexes(): void {
		$unprefixedTableName = $this->unprefixedTable('removed_index');
		$table               = new IndexReconciliationTable($unprefixedTableName, true);

		$this->schema->create($table->blueprint());
		$this->schema->execute(sprintf(
			'ALTER TABLE %s ADD INDEX %s (%s)',
			$this->database->quoteIdentifier($this->database->tableName($table)),
			$this->database->quoteIdentifier('external_tenant'),
			$this->database->quoteIdentifier('tenant')
		));
		$this->assertTrue($this->schema->hasIndex($table, 'email_unique'));

		$change = Blueprint::for($table);
		$change->dropIndex('email_unique');
		$this->schema->alter($change);

		$this->assertFalse($this->schema->hasIndex($table, 'email_unique'));
		$this->assertTrue($this->schema->hasIndex($table, 'external_tenant'));
	}

	public function test_schema_explicitly_adds_an_auto_increment_attribute(): void {
		$unprefixedTableName = $this->unprefixedTable('column_extra');
		$tableObject         = new TestTable($unprefixedTableName);
		$table               = $this->database->tableName($tableObject);

		$this->database->execute(sprintf(
			'CREATE TABLE %s (id bigint(20) unsigned NOT NULL, PRIMARY KEY (id)) %s',
			$this->database->quoteIdentifier($table),
			$this->database->charsetCollate()
		));

		$change = Blueprint::for($tableObject);
		$change->column(new Column('id', 'bigint', 20, unsigned: true))->autoIncrement()->change();
		$this->schema->alter($change);

		$column = $this->database->row('SHOW COLUMNS FROM %i WHERE Field = %s', $table, 'id');

		$this->assertSame('auto_increment', strtolower((string) ($column['Extra'] ?? '')));
	}

	public function test_migration_repository_persists_records_in_wordpress(): void {
		$tableName      = $this->unprefixedTable('migrations');
		$schema         = $this->schema;
		$migrationTable = new MigrationTable($tableName, $this->database);
		$table          = $this->database->tableName($migrationTable);
		$repository     = new Repository($migrationTable);

		$this->assertFalse($schema->hasTable($migrationTable));

		(new StoreSchema(
			$schema,
			$this->reconciler,
			$migrationTable,
			new LockTable($this->unprefixedTable('repository_locks'), $this->database)
		))->initializeLedger();

		$this->assertTrue($schema->hasTable($migrationTable));
		$this->assertSame($table, $migrationTable->name());
		$this->assertNull($repository->latestBatch());

		$repository->recordRun('2026_06_23_000001_create_example_table', 1);
		$record = $repository->all()['2026_06_23_000001_create_example_table'];

		$this->assertGreaterThan(0, $record->id);
		$this->assertTrue($repository->hasRun('2026_06_23_000001_create_example_table'));
		$this->assertSame(1, $repository->latestBatch());
		$this->assertArrayHasKey('2026_06_23_000001_create_example_table', $repository->all());
		$this->assertCount(1, $repository->recordsForBatch(1));
		$this->assertTrue($repository->deleteRun('2026_06_23_000001_create_example_table'));
		$this->assertFalse($repository->hasRun('2026_06_23_000001_create_example_table'));

		$repository->recordRun('CreateReports', 2);
		$repository->recordRun('createreports', 2);

		$this->assertTrue($repository->hasRun('CreateReports'));
		$this->assertTrue($repository->hasRun('createreports'));
		$this->assertSame(
			['CreateReports', 'createreports'],
			array_map(
				static fn (Record $record): string => $record->migration,
				$repository->recordsForBatch(2)
			)
		);

		$schema->drop($migrationTable);

		$this->assertFalse($schema->hasTable($migrationTable));
	}

	public function test_database_lock_coordinates_ownership_in_wordpress(): void {
		$tableName = $this->unprefixedTable('locks');
		$wpSchema  = $this->schema;
		$lockTable = new LockTable($tableName, $this->database);
		$table     = $this->database->tableName($lockTable);
		$lock      = new DatabaseLock($this->database, $lockTable);

		$this->assertFalse($wpSchema->hasTable($lockTable));

		(new StoreSchema(
			$wpSchema,
			$this->reconciler,
			new MigrationTable($this->unprefixedTable('lock_migrations'), $this->database),
			$lockTable
		))->initializeLock();

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
		$tableName = $this->unprefixedTable('expired_locks');
		$wpSchema  = $this->schema;
		$lockTable = new LockTable($tableName, $this->database);
		$table     = $this->database->tableName($lockTable);
		$lock      = new DatabaseLock($this->database, $lockTable);

		(new StoreSchema(
			$wpSchema,
			$this->reconciler,
			new MigrationTable($this->unprefixedTable('expired_lock_migrations'), $this->database),
			$lockTable
		))->initializeLock();

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
		$tableName = $this->unprefixedTable('exact_locks');
		$wpSchema  = $this->schema;
		$lockTable = new LockTable($tableName, $this->database);
		$table     = $this->database->tableName($lockTable);
		$lock      = new DatabaseLock($this->database, $lockTable);

		(new StoreSchema(
			$wpSchema,
			$this->reconciler,
			new MigrationTable($this->unprefixedTable('exact_lock_migrations'), $this->database),
			$lockTable
		))->initializeLock();

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
		$tableName = $this->unprefixedTable('previous_lock_schema');
		$wpSchema  = $this->schema;
		$lockTable = new LockTable($tableName, $this->database);
		$table     = $this->database->tableName($lockTable);

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

		(new StoreSchema(
			$wpSchema,
			$this->reconciler,
			new MigrationTable($this->unprefixedTable('previous_lock_migrations'), $this->database),
			$lockTable
		))->initializeLock();

		$name       = $this->database->row('SHOW COLUMNS FROM %i WHERE Field = %s', $table, 'name');
		$owner      = $this->database->row('SHOW COLUMNS FROM %i WHERE Field = %s', $table, 'owner');
		$expiration = $this->database->row('SHOW COLUMNS FROM %i WHERE Field = %s', $table, 'expires_at');

		$this->assertSame('varbinary(191)', strtolower((string) ($name['Type'] ?? '')));
		$this->assertSame('varbinary(64)', strtolower((string) ($owner['Type'] ?? '')));
		$this->assertSame('datetime(6)', strtolower((string) ($expiration['Type'] ?? '')));

		$wpSchema->drop($lockTable);
	}

	public function test_migration_table_reconciles_case_insensitive_identifiers(): void {
		$tableName      = $this->unprefixedTable('previous_migration_schema');
		$wpSchema       = $this->schema;
		$migrationTable = new MigrationTable($tableName, $this->database);
		$table          = $this->database->tableName($migrationTable);

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

		(new StoreSchema(
			$wpSchema,
			$this->reconciler,
			$migrationTable,
			new LockTable($this->unprefixedTable('previous_migration_locks'), $this->database)
		))->initializeLedger();

		$migration = $this->database->row('SHOW COLUMNS FROM %i WHERE Field = %s', $table, 'migration');

		$this->assertSame('varbinary(191)', strtolower((string) ($migration['Type'] ?? '')));

		$wpSchema->drop($migrationTable);
	}

	public function test_provider_registers_wordpress_prefixed_database_services(): void {
		$container = $this->newContainer();

		$container->register(DatabaseProvider::class);

		$this->assertInstanceOf(Database::class, $container->get(Database::class));
		$this->assertInstanceOf(Database::class, $container->get(DatabaseContract::class));
		$this->assertInstanceOf(Schema::class, $container->get(Schema::class));
		$this->assertSame($GLOBALS['wpdb']->prefix . 'nx_foundation_migrations', $container->get(MigrationTable::class)->name());
		$this->assertSame($GLOBALS['wpdb']->prefix . 'nx_foundation_locks', $container->get(LockTable::class)->name());
		$this->assertInstanceOf(Repository::class, $container->get(MigrationRecordRepositoryContract::class));
		$this->assertInstanceOf(Migrator::class, $container->get(Migrator::class));
		$this->assertFalse($container->has(Lock::class));
	}

	/**
	 * Apply a complete replacement declaration for the test description column.
	 */
	private function alterDescriptionComment(CommentedTable $table, ?string $comment): void {
		$change      = Blueprint::for($table);
		$description = $change->text('description');

		if ($comment !== null) {
			$description->comment($comment);
		}

		$description->change();
		$this->schema->alter($change);
	}

	/**
	 * Change comments while retaining every modeled column attribute.
	 */
	private function alterReconciliationComments(CommentReconciliationTable $table, ?string $comment): void {
		$change      = Blueprint::for($table);
		$id          = $change->column(new Column('id', 'bigint', 20, unsigned: true))->autoIncrement();
		$description = $change->string('description', 100)->nullable()->default('fallback');

		if ($comment !== null) {
			$id->comment($comment);
			$description->comment($comment);
		}

		$id->change();
		$description->change();
		$this->schema->alter($change);
	}

	private function assertCommentReconciliationTable(string $unprefixedTableName, string $comment): void {
		$table = new CommentReconciliationTable($unprefixedTableName, null);
		$id    = $this->database->row(
			'SHOW FULL COLUMNS FROM %i WHERE Field = %s',
			$this->database->tableName($table),
			'id'
		);
		$description = $this->database->row(
			'SHOW FULL COLUMNS FROM %i WHERE Field = %s',
			$this->database->tableName($table),
			'description'
		);

		$this->assertStringContainsString('bigint', strtolower((string) ($id['Type'] ?? '')));
		$this->assertStringContainsString('unsigned', strtolower((string) ($id['Type'] ?? '')));
		$this->assertSame('NO', $id['Null'] ?? null);
		$this->assertSame('auto_increment', strtolower((string) ($id['Extra'] ?? '')));
		$this->assertSame($comment, $id['Comment'] ?? null);
		$this->assertSame('varchar(100)', strtolower((string) ($description['Type'] ?? '')));
		$this->assertSame('YES', $description['Null'] ?? null);
		$this->assertSame('fallback', $description['Default'] ?? null);
		$this->assertSame($comment, $description['Comment'] ?? null);
	}

	private function table(string $suffix): string {
		return $this->database->tableName(new TestTable($this->unprefixedTable($suffix)));
	}

	private function unprefixedTable(string $suffix): string {
		$table = 'foundation_' . $suffix . '_' . str_replace('.', '_', uniqid('', true));

		$this->tables[] = $this->database->tableName(new TestTable($table));

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
		return (new ContainerFactory())->create(new ArrayConfiguration());
	}
}
