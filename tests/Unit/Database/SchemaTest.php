<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database;

use StellarWP\Foundation\Database\Schema;
use StellarWP\Foundation\Database\Schema\Reconciler;
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
		$schema                 = new Schema($database, new Reconciler($database, new RecordingSchemaExecutor()));
		$table                  = new TestTable('example_table', 'example%');
		$indexTable             = new TestTable('index_table', 'example');

		$this->assertTrue($schema->hasTable($table));
		$this->assertTrue($schema->hasIndex($indexTable, 'example_key'));
		$this->assertStringContainsString("SHOW TABLES LIKE 'wp\\\\_example\\\\%'", $database->rowQueries[0]);
		$this->assertStringContainsString('SHOW INDEX FROM `wp_example`', $database->rowQueries[1]);
	}

	public function test_it_drops_indexes(): void {
		$database = new FakeDatabase();
		$schema   = new Schema($database, new Reconciler($database, new RecordingSchemaExecutor()));
		$table    = new TestTable('example_table', 'example');

		$schema->dropIndex($table, 'example_key');

		$this->assertSame('ALTER TABLE `wp_example` DROP INDEX `example_key`', $database->executed[0]);
	}

	public function test_it_exposes_identifier_helpers(): void {
		$database = new FakeDatabase();
		$schema   = new Schema($database, new Reconciler($database, new RecordingSchemaExecutor()));

		$this->assertSame('`weird``table`', $schema->quoteIdentifier('weird`table'));
	}
}
