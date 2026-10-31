<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Schema;
use StellarWP\Foundation\Database\Schema\Editor;
use StellarWP\Foundation\Database\Schema\Reconciler;
use StellarWP\Foundation\Database\Table\Blueprint;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchemaExecutor;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestTable;
use StellarWP\Foundation\Tests\TestCase;

final class SchemaTest extends TestCase
{
	public function test_it_checks_tables_and_indexes(): void {
		$database               = new FakeDatabase();
		$database->rowResults[] = ['table' => 'wp_example'];
		$database->rowResults[] = ['Key_name' => 'example_key'];
		$schema                 = $this->schema($database);
		$table                  = new TestTable('example%');
		$indexTable             = new TestTable('example');

		$this->assertTrue($schema->hasTable($table));
		$this->assertTrue($schema->hasIndex($indexTable, 'example_key'));
		$this->assertStringContainsString("SHOW TABLES LIKE 'wp\\\\_example\\\\%'", $database->rowQueries[0]);
		$this->assertStringContainsString('SHOW INDEX FROM `wp_example`', $database->rowQueries[1]);
	}

	public function test_it_alters_a_table_from_explicit_blueprint_operations(): void {
		$database = new FakeDatabase();
		$schema   = $this->schema($database);
		$table    = new TestTable('example');
		$change   = Blueprint::for($table)->dropIndex('example_key');

		$database->rowResults = [
			['table' => 'wp_example'],
			['Key_name' => 'example_key'],
			null,
		];

		$schema->alter($change);

		$this->assertSame('ALTER TABLE `wp_example` DROP INDEX `example_key`', $database->executed[0]);
	}

	public function test_it_creates_a_table_from_a_migration_blueprint(): void {
		$database = new FakeDatabase();
		$executor = new RecordingSchemaExecutor();
		$schema   = $this->schema($database, $executor);
		$table    = new TestTable('example');

		$database->rowResults[]  = null;
		$database->rowResults[]  = ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'];
		$database->rowsResults[] = [[
			'Key_name'     => 'PRIMARY',
			'Non_unique'   => 0,
			'Seq_in_index' => 1,
			'Column_name'  => 'id',
			'Sub_part'     => null,
			'Index_type'   => 'BTREE',
			'Collation'    => 'A',
		]];

		$schema->create($table->blueprint());

		$this->assertStringContainsString('CREATE TABLE `wp_example`', $executor->statements[0]);
	}

	public function test_it_verifies_an_existing_table_without_reapplying_an_old_creation_blueprint(): void {
		$database = new FakeDatabase();
		$executor = new RecordingSchemaExecutor();
		$schema   = $this->schema($database, $executor);
		$table    = new TestTable('example');

		$database->rowResults[]  = ['table' => 'wp_example'];
		$database->rowResults[]  = ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'];
		$database->rowsResults[] = [[
			'Key_name'     => 'PRIMARY',
			'Non_unique'   => 0,
			'Seq_in_index' => 1,
			'Column_name'  => 'id',
			'Sub_part'     => null,
			'Index_type'   => 'BTREE',
			'Collation'    => 'A',
		]];

		$schema->create($table->blueprint());

		$this->assertSame([], $executor->statements);
	}

	public function test_it_rejects_an_incompatible_existing_table_without_reapplying_the_creation_blueprint(): void {
		$database = new FakeDatabase();
		$executor = new RecordingSchemaExecutor();
		$schema   = $this->schema($database, $executor);
		$table    = new TestTable('example');

		$database->rowResults[]  = ['table' => 'wp_example'];
		$database->rowResults[]  = ['Type' => 'bigint(20)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''];
		$database->rowsResults[] = [[
			'Key_name'     => 'PRIMARY',
			'Non_unique'   => 0,
			'Seq_in_index' => 1,
			'Column_name'  => 'id',
			'Sub_part'     => null,
			'Index_type'   => 'BTREE',
			'Collation'    => 'A',
		]];

		try {
			$schema->create($table->blueprint());
			$this->fail('Expected the incompatible existing table to be rejected.');
		} catch (DatabaseException $exception) {
			$this->assertStringContainsString('column id expected type bigint(20) unsigned, found bigint(20)', $exception->getMessage());
		}

		$this->assertSame([], $executor->statements);
	}

	public function test_it_exposes_identifier_helpers(): void {
		$database = new FakeDatabase();
		$schema   = $this->schema($database);

		$this->assertSame('`weird``table`', $schema->quoteIdentifier('weird`table'));
	}

	private function schema(FakeDatabase $database, ?RecordingSchemaExecutor $executor = null): Schema {
		$reconciler = new Reconciler($database, $executor ?? new RecordingSchemaExecutor());

		return new Schema($database, $reconciler, new Editor($database, $reconciler));
	}
}
