<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table;

use StellarWP\Foundation\Database\Table\CreateTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchema;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestTable;
use StellarWP\Foundation\Tests\TestCase;

final class CreateTableTest extends TestCase
{
	public function test_it_uses_the_explicit_migration_id(): void {
		$migration = new CreateTable('foundation_example_table', (new TestTable('example'))->blueprint());

		$this->assertSame('foundation_example_table', $migration->id());
	}

	public function test_it_creates_missing_tables(): void {
		$table     = new TestTable('example');
		$migration = new CreateTable('foundation_example_table', $table->blueprint());
		$schema    = new RecordingSchema();

		$migration->up($schema);

		$this->assertTrue($schema->hasTable($table));
	}

	public function test_it_delegates_existing_table_verification_to_schema(): void {
		$table     = new TestTable('example');
		$migration = new CreateTable('foundation_example_table', $table->blueprint());
		$schema    = new RecordingSchema();

		$schema->tables['example'] = true;

		$migration->up($schema);

		$this->assertSame(['create:example'], $schema->statements);
	}

	public function test_it_drops_tables_when_rolled_back(): void {
		$table     = new TestTable('example');
		$migration = new CreateTable('foundation_example_table', $table->blueprint());
		$schema    = new RecordingSchema();

		$schema->tables['example'] = true;

		$migration->down($schema);

		$this->assertFalse($schema->hasTable($table));
	}
}
