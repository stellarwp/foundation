<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Schema\Reconciler;
use StellarWP\Foundation\Database\Table\Blueprint;
use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\IndexType;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\CommentedTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\IndexReconciliationTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchemaExecutor;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\SchemaReconciliationTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestTable;
use StellarWP\Foundation\Tests\TestCase;

final class ReconcilerTest extends TestCase
{
	public function test_it_builds_table_definitions_for_the_schema_executor(): void {
		$database                = new FakeDatabase();
		$database->rowResults[]  = ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'];
		$database->rowsResults[] = [self::indexRow('PRIMARY', 0, 1, 'id')];
		$executor                = new RecordingSchemaExecutor();
		$reconciler              = new Reconciler($database, $executor);

		$reconciler->reconcile((new TestTable('example'))->blueprint());

		$this->assertStringContainsString('CREATE TABLE `wp_example`', $executor->statements[0]);
		$this->assertSame("SHOW FULL COLUMNS FROM `wp_example` WHERE Field = 'id'", $database->rowQueries[0]);
		$this->assertSame('SHOW INDEX FROM `wp_example`', $database->rowsQueries[0]);
	}

	public function test_it_accepts_matching_column_defaults_and_nullability(): void {
		$database             = new FakeDatabase();
		$database->rowResults = [
			['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'],
			['Type' => 'int(10)', 'Null' => 'NO', 'Default' => '5', 'Extra' => ''],
			['Type' => 'datetime', 'Null' => 'YES', 'Default' => null, 'Extra' => ''],
			['Type' => 'varchar(191)', 'Null' => 'NO', 'Default' => '', 'Extra' => ''],
			['Type' => 'decimal(10,2)', 'Null' => 'NO', 'Default' => '1.25', 'Extra' => ''],
			['Type' => 'bit(1)', 'Null' => 'NO', 'Default' => "b'1'", 'Extra' => ''],
		];
		$database->rowsResults[] = [self::indexRow('PRIMARY', 0, 1, 'id')];
		$reconciler              = new Reconciler($database, new RecordingSchemaExecutor());

		$reconciler->reconcile((new SchemaReconciliationTable('example', 5, true))->blueprint());

		$this->assertSame([], $database->executed);
	}

	public function test_it_accepts_matching_index_metadata(): void {
		$database                = new FakeDatabase();
		$database->rowResults    = self::indexTableColumns();
		$database->rowsResults[] = [
			self::indexRow('PRIMARY', 0, 1, 'id'),
			self::indexRow('email_unique', 0, 1, 'email'),
		];

		(new Reconciler($database, new RecordingSchemaExecutor()))
			->reconcile((new IndexReconciliationTable('example', true))->blueprint());

		$this->assertSame('SHOW INDEX FROM `wp_example`', $database->rowsQueries[0]);
	}

	public function test_it_orders_composite_index_metadata_by_sequence(): void {
		$database                = new FakeDatabase();
		$database->rowResults    = self::indexTableColumns();
		$database->rowsResults[] = [
			self::indexRow('PRIMARY', 0, 1, 'id'),
			self::indexRow('email_unique', 0, 2, 'tenant'),
			self::indexRow('email_unique', 0, 1, 'email'),
		];

		(new Reconciler($database, new RecordingSchemaExecutor()))
			->reconcile((new IndexReconciliationTable('example', true, ['email', 'tenant']))->blueprint());

		$this->assertSame('SHOW INDEX FROM `wp_example`', $database->rowsQueries[0]);
	}

	public function test_it_rejects_reordered_composite_index_columns(): void {
		$database                = new FakeDatabase();
		$database->rowResults    = self::indexTableColumns();
		$database->rowsResults[] = [
			self::indexRow('PRIMARY', 0, 1, 'id'),
			self::indexRow('email_unique', 0, 1, 'tenant'),
			self::indexRow('email_unique', 0, 2, 'email'),
		];

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('index email_unique expected UNIQUE (email, tenant), found UNIQUE (tenant, email)');

		(new Reconciler($database, new RecordingSchemaExecutor()))
			->reconcile((new IndexReconciliationTable('example', true, ['email', 'tenant']))->blueprint());
	}

	public function test_it_rejects_descending_index_columns(): void {
		$database                = new FakeDatabase();
		$database->rowResults    = self::indexTableColumns();
		$database->rowsResults[] = [
			self::indexRow('PRIMARY', 0, 1, 'id'),
			self::indexRow('email_unique', 0, 1, 'email', collation: 'D'),
		];

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('index email_unique expected UNIQUE (email), found UNIQUE (email desc)');

		(new Reconciler($database, new RecordingSchemaExecutor()))
			->reconcile((new IndexReconciliationTable('example', true))->blueprint());
	}

	public function test_it_rejects_a_semantically_different_index_type(): void {
		$database                = new FakeDatabase();
		$database->rowResults    = self::indexTableColumns();
		$database->rowsResults[] = [
			self::indexRow('PRIMARY', 0, 1, 'id'),
			self::indexRow('email_unique', 1, 1, 'email', indexType: 'FULLTEXT'),
		];

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('expected KEY (email), found FULLTEXT (email)');

		(new Reconciler($database, new RecordingSchemaExecutor()))
			->reconcile((new IndexReconciliationTable('example', true, indexType: IndexType::KEY))->blueprint());
	}

	public function test_it_accepts_hash_as_an_index_storage_method(): void {
		$database                = new FakeDatabase();
		$database->rowResults    = self::indexTableColumns();
		$database->rowsResults[] = [
			self::indexRow('PRIMARY', 0, 1, 'id'),
			self::indexRow('email_unique', 1, 1, 'email', indexType: 'HASH'),
		];

		(new Reconciler($database, new RecordingSchemaExecutor()))
			->reconcile((new IndexReconciliationTable('example', true, indexType: IndexType::KEY))->blueprint());

		$this->assertSame('SHOW INDEX FROM `wp_example`', $database->rowsQueries[0]);
	}

	/**
	 * @dataProvider indexDifferences
	 *
	 * @param list<array<string, mixed>> $indexes
	 */
	#[DataProvider('indexDifferences')]
	public function test_it_rejects_indexes_that_do_not_match_the_definition(
		bool $includeUniqueIndex,
		array $indexes,
		string $message
	): void {
		$database                = new FakeDatabase();
		$database->rowResults    = self::indexTableColumns();
		$database->rowsResults[] = $indexes;

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage($message);

		(new Reconciler($database, new RecordingSchemaExecutor()))
			->reconcile((new IndexReconciliationTable('example', $includeUniqueIndex))->blueprint());
	}

	/**
	 * @return iterable<string, array{bool, list<array<string, mixed>>, string}>
	 */
	public static function indexDifferences(): iterable {
		yield 'missing' => [
			true,
			[self::indexRow('PRIMARY', 0, 1, 'id')],
			'index email_unique expected UNIQUE (email), found missing',
		];

		yield 'changed uniqueness' => [
			true,
			[
				self::indexRow('PRIMARY', 0, 1, 'id'),
				self::indexRow('email_unique', 1, 1, 'email'),
			],
			'index email_unique expected UNIQUE (email), found KEY (email)',
		];

		yield 'changed columns' => [
			true,
			[
				self::indexRow('PRIMARY', 0, 1, 'id'),
				self::indexRow('email_unique', 0, 1, 'id'),
			],
			'index email_unique expected UNIQUE (email), found UNIQUE (id)',
		];

		yield 'column prefix' => [
			true,
			[
				self::indexRow('PRIMARY', 0, 1, 'id'),
				self::indexRow('email_unique', 0, 1, 'email', 32),
			],
			'index email_unique expected UNIQUE (email), found UNIQUE (email(32))',
		];
	}

	public function test_it_ignores_indexes_not_declared_by_the_migration_blueprint(): void {
		$database                = new FakeDatabase();
		$database->rowResults    = self::indexTableColumns();
		$database->rowsResults[] = [
			self::indexRow('PRIMARY', 0, 1, 'id'),
			self::indexRow('externally_managed_search', 1, 1, 'email', indexType: 'FULLTEXT'),
		];

		(new Reconciler($database, new RecordingSchemaExecutor()))
			->reconcile((new IndexReconciliationTable('example', false))->blueprint());

		$this->addToAssertionCount(1);
	}

	public function test_it_ignores_unsupported_metadata_for_an_undeclared_functional_index(): void {
		$database                = new FakeDatabase();
		$database->rowResults    = self::indexTableColumns();
		$database->rowsResults[] = [
			self::indexRow('PRIMARY', 0, 1, 'id'),
			[
				'Key_name'     => 'externally_managed_expression',
				'Non_unique'   => 1,
				'Seq_in_index' => 1,
				'Column_name'  => null,
				'Expression'   => 'lower(`email`)',
				'Sub_part'     => null,
				'Index_type'   => 'BTREE',
				'Collation'    => 'A',
			],
		];

		(new Reconciler($database, new RecordingSchemaExecutor()))
			->reconcile((new IndexReconciliationTable('example', false))->blueprint());

		$this->addToAssertionCount(1);
	}

	public function test_it_preserves_case_and_spacing_inside_custom_type_literals(): void {
		$database               = new FakeDatabase();
		$database->rowResults[] = [
			'Type'    => "enum('a b')",
			'Null'    => 'NO',
			'Default' => null,
			'Extra'   => '',
			'Comment' => '',
		];
		$blueprint = Blueprint::for(new TestTable('example'));
		$blueprint->column(new Column('status', "enum('A  B')"));

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage("column status expected type enum('A  B'), found enum('a b')");

		(new Reconciler($database, new RecordingSchemaExecutor()))->verify($blueprint);
	}

	public function test_it_rejects_a_table_using_an_incompatible_wordpress_charset(): void {
		$database              = new FakeDatabase();
		$database->tableStatus = ['Collation' => 'latin1_swedish_ci'];
		$database->rowResults  = [[
			'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment',
		]];
		$database->rowsResults[] = [self::indexRow('PRIMARY', 0, 1, 'id')];

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('table expected collation utf8mb4_*, found latin1_swedish_ci');

		(new Reconciler($database, new RecordingSchemaExecutor()))
			->reconcile((new TestTable('example'))->blueprint());
	}

	public function test_it_rejects_a_text_column_using_an_incompatible_wordpress_charset(): void {
		$database               = new FakeDatabase();
		$database->rowResults[] = [
			'Type'      => 'varchar(20)',
			'Null'      => 'NO',
			'Default'   => null,
			'Extra'     => '',
			'Comment'   => '',
			'Collation' => 'latin1_swedish_ci',
		];
		$blueprint = Blueprint::for(new TestTable('example'));
		$blueprint->string('status', 20);

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('column status expected collation utf8mb4_*, found latin1_swedish_ci');

		(new Reconciler($database, new RecordingSchemaExecutor()))->verify($blueprint);
	}

	public function test_it_rejects_invalid_index_metadata(): void {
		$database                = new FakeDatabase();
		$database->rowResults    = self::indexTableColumns();
		$database->rowsResults[] = [['Key_name' => 'PRIMARY']];

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('returned invalid index metadata for wp_example');

		(new Reconciler($database, new RecordingSchemaExecutor()))
			->reconcile((new IndexReconciliationTable('example', true))->blueprint());
	}

	public function test_it_fails_when_column_defaults_and_nullability_remain_unapplied(): void {
		$database             = new FakeDatabase();
		$database->rowResults = [
			['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'],
			['Type' => 'int(10)', 'Null' => 'NO', 'Default' => 'not-an-integer', 'Extra' => ''],
			['Type' => 'datetime', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
			['Type' => 'varchar(191)', 'Null' => 'NO', 'Default' => '', 'Extra' => ''],
			['Type' => 'decimal(10,2)', 'Null' => 'NO', 'Default' => '1.25', 'Extra' => ''],
			['Type' => 'bit(1)', 'Null' => 'NO', 'Default' => "b'1'", 'Extra' => ''],
		];
		$reconciler = new Reconciler($database, new RecordingSchemaExecutor());

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('column attempts expected DEFAULT 5, found DEFAULT not-an-integer; column completed_at expected NULL, found NOT NULL');

		$reconciler->reconcile((new SchemaReconciliationTable('example', 5, true))->blueprint());
	}

	public function test_it_does_not_treat_a_missing_default_as_an_empty_string(): void {
		$database             = new FakeDatabase();
		$database->rowResults = [
			['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'],
			['Type' => 'int(10)', 'Null' => 'NO', 'Default' => '5', 'Extra' => ''],
			['Type' => 'datetime', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
			['Type' => 'varchar(191)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
			['Type' => 'decimal(10,2)', 'Null' => 'NO', 'Default' => '1.25', 'Extra' => ''],
			['Type' => 'bit(1)', 'Null' => 'NO', 'Default' => "b'1'", 'Extra' => ''],
		];
		$reconciler = new Reconciler($database, new RecordingSchemaExecutor());

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage("column label expected DEFAULT '', found DEFAULT NULL");

		$reconciler->reconcile((new SchemaReconciliationTable('example', 5, false))->blueprint());
	}

	public function test_it_rejects_unapplied_column_extra_attributes(): void {
		$database               = new FakeDatabase();
		$database->rowResults[] = ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => ''];
		$reconciler             = new Reconciler($database, new RecordingSchemaExecutor());

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('column id expected extra auto_increment, found none');

		$reconciler->reconcile((new TestTable('example'))->blueprint());
	}

	public function test_it_reports_column_and_index_differences_together(): void {
		$database                = new FakeDatabase();
		$database->rowResults    = self::indexTableColumns();
		$database->rowResults[0] = ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => ''];
		$database->rowsResults[] = [self::indexRow('PRIMARY', 0, 1, 'id')];

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage(
			'column id expected extra auto_increment, found none; index email_unique expected UNIQUE (email), found missing'
		);

		(new Reconciler($database, new RecordingSchemaExecutor()))
			->reconcile((new IndexReconciliationTable('example', true))->blueprint());
	}

	public function test_it_reconciles_an_unapplied_column_comment(): void {
		$database             = new FakeDatabase();
		$database->rowResults = [
			['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment', 'Comment' => ''],
			['Type' => 'text', 'Null' => 'NO', 'Default' => null, 'Extra' => '', 'Comment' => ''],
			['Type' => 'text', 'Null' => 'NO', 'Default' => null, 'Extra' => '', 'Comment' => 'Public description'],
		];
		$database->rowsResults[] = [self::indexRow('PRIMARY', 0, 1, 'id')];
		$reconciler              = new Reconciler($database, new RecordingSchemaExecutor());

		$reconciler->reconcile((new CommentedTable('example', 'Public description'))->blueprint());

		$this->assertSame([
			'ALTER TABLE `wp_example` MODIFY COLUMN `description` text NOT NULL COMMENT \'Public description\'',
		], $database->executed);
	}

	public function test_it_does_not_replace_a_column_when_its_comment_matches(): void {
		$database             = new FakeDatabase();
		$database->rowResults = [
			['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment', 'Comment' => ''],
			['Type' => 'text', 'Null' => 'NO', 'Default' => null, 'Extra' => '', 'Comment' => 'Public description'],
		];
		$database->rowsResults[] = [self::indexRow('PRIMARY', 0, 1, 'id')];

		(new Reconciler($database, new RecordingSchemaExecutor()))
			->reconcile((new CommentedTable('example', 'Public description'))->blueprint());

		$this->assertSame([], $database->executed);
	}

	public function test_it_removes_an_unapplied_column_comment(): void {
		$database             = new FakeDatabase();
		$database->rowResults = [
			['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment', 'Comment' => ''],
			['Type' => 'text', 'Null' => 'NO', 'Default' => null, 'Extra' => '', 'Comment' => 'Old description'],
			['Type' => 'text', 'Null' => 'NO', 'Default' => null, 'Extra' => '', 'Comment' => ''],
		];
		$database->rowsResults[] = [self::indexRow('PRIMARY', 0, 1, 'id')];
		$reconciler              = new Reconciler($database, new RecordingSchemaExecutor());

		$reconciler->reconcile((new CommentedTable('example', null))->blueprint());

		$this->assertSame([
			'ALTER TABLE `wp_example` MODIFY COLUMN `description` text NOT NULL',
		], $database->executed);
	}

	public function test_it_rejects_a_column_comment_that_remains_unapplied(): void {
		$database             = new FakeDatabase();
		$database->rowResults = [
			['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment', 'Comment' => ''],
			['Type' => 'text', 'Null' => 'NO', 'Default' => null, 'Extra' => '', 'Comment' => ''],
			['Type' => 'text', 'Null' => 'NO', 'Default' => null, 'Extra' => '', 'Comment' => ''],
		];
		$database->rowsResults[] = [self::indexRow('PRIMARY', 0, 1, 'id')];

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('column description expected comment Public description, found none');

		(new Reconciler($database, new RecordingSchemaExecutor()))
			->reconcile((new CommentedTable('example', 'Public description'))->blueprint());
	}

	public function test_it_rejects_missing_column_metadata(): void {
		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('could not inspect wp_example.id');

		(new Reconciler(new FakeDatabase(), new RecordingSchemaExecutor()))
			->reconcile((new TestTable('example'))->blueprint());
	}

	public function test_it_rejects_invalid_column_metadata(): void {
		$database               = new FakeDatabase();
		$database->rowResults[] = ['Type' => 'bigint(20) unsigned', 'Null' => 'MAYBE', 'Default' => null, 'Extra' => ''];

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('returned invalid column metadata for wp_example.id');

		(new Reconciler($database, new RecordingSchemaExecutor()))
			->reconcile((new TestTable('example'))->blueprint());
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function indexTableColumns(): array {
		return [
			['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'],
			['Type' => 'varchar(191)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
			['Type' => 'varchar(191)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function indexRow(
		string $name,
		int $nonUnique,
		int $sequence,
		string $column,
		?int $subPart = null,
		string $indexType = 'BTREE',
		?string $collation = 'A'
	): array {
		return [
			'Key_name'     => $name,
			'Non_unique'   => $nonUnique,
			'Seq_in_index' => $sequence,
			'Column_name'  => $column,
			'Sub_part'     => $subPart,
			'Index_type'   => $indexType,
			'Collation'    => $collation,
		];
	}
}
