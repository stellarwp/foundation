<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database;

use StellarWP\Foundation\Database\Schema;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchemaExecutor;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestTable;
use StellarWP\Foundation\Tests\TestCase;

final class SchemaTest extends TestCase
{
	public function test_it_runs_create_or_update_sql_through_the_schema_executor(): void {
		$executor = new RecordingSchemaExecutor();
		$schema   = new Schema(new FakeDatabase(), $executor);

		$schema->createOrUpdateSql('CREATE TABLE example (id bigint)');

		$this->assertSame(['CREATE TABLE example (id bigint)'], $executor->statements);
	}

	public function test_it_builds_table_definitions_for_the_schema_executor(): void {
		$executor = new RecordingSchemaExecutor();
		$schema   = new Schema(new FakeDatabase(), $executor);

		$schema->createOrUpdate(new TestTable('example', 'wp_example'));

		$this->assertStringContainsString('CREATE TABLE `wp_example`', $executor->statements[0]);
	}

	public function test_it_checks_tables_and_indexes(): void {
		$database               = new FakeDatabase();
		$database->rowResults[] = ['table' => 'wp_example'];
		$database->rowResults[] = ['Key_name' => 'example_key'];
		$schema                 = new Schema($database, new RecordingSchemaExecutor());

		$this->assertTrue($schema->hasTable('wp_example%'));
		$this->assertTrue($schema->hasIndex('wp_example', 'example_key'));
		$this->assertStringContainsString("SHOW TABLES LIKE 'wp\\\\_example\\\\%'", $database->rowQueries[0]);
		$this->assertStringContainsString('SHOW INDEX FROM `wp_example`', $database->rowQueries[1]);
	}

	public function test_it_drops_indexes(): void {
		$database = new FakeDatabase();
		$schema   = new Schema($database, new RecordingSchemaExecutor());

		$schema->dropIndex('wp_example', 'example_key');

		$this->assertSame('ALTER TABLE `wp_example` DROP INDEX `example_key`', $database->executed[0]);
	}

	public function test_it_exposes_identifier_helpers(): void {
		$schema = new Schema(new FakeDatabase(), new RecordingSchemaExecutor());

		$this->assertSame('`weird``table`', $schema->quoteIdentifier('weird`table'));
	}
}
