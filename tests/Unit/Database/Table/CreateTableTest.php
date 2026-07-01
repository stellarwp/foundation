<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table;

use StellarWP\Foundation\Database\Table\CreateTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchema;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestTable;
use StellarWP\Foundation\Tests\TestCase;

final class CreateTableTest extends TestCase
{
	public function test_it_uses_the_table_id_as_the_migration_id(): void {
		$migration = new CreateTable(new TestTable('foundation_example_table', 'wp_example'));

		$this->assertSame('foundation_example_table', $migration->id());
	}

	public function test_it_creates_missing_tables(): void {
		$table     = new TestTable('foundation_example_table', 'wp_example');
		$migration = new CreateTable($table);
		$schema    = new RecordingSchema();

		$migration->up($schema);

		$this->assertTrue($schema->hasTable($table));
	}

	public function test_it_does_not_create_existing_tables(): void {
		$table     = new TestTable('foundation_example_table', 'wp_example');
		$migration = new CreateTable($table);
		$schema    = new RecordingSchema();

		$schema->tables['wp_example'] = true;

		$migration->up($schema);

		$this->assertSame([], $schema->statements);
	}

	public function test_it_drops_tables_when_rolled_back(): void {
		$table     = new TestTable('foundation_example_table', 'wp_example');
		$migration = new CreateTable($table);
		$schema    = new RecordingSchema();

		$schema->tables['wp_example'] = true;

		$migration->down($schema);

		$this->assertFalse($schema->hasTable($table));
	}
}
