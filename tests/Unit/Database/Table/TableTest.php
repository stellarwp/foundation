<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table;

use ReflectionMethod;
use StellarWP\Foundation\Database\Table\Table;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestDatabaseTable;
use StellarWP\Foundation\Tests\TestCase;

final class TableTest extends TestCase
{
	public function test_the_database_extension_seam_is_protected_and_final(): void {
		$method = new ReflectionMethod(Table::class, 'database');

		$this->assertTrue($method->isProtected());
		$this->assertTrue($method->isFinal());
	}

	public function test_it_resolves_the_current_database_scope_for_each_operation(): void {
		$database = new FakeDatabase();
		$table    = new TestDatabaseTable('reports', $database);

		$this->assertSame('reports', $table->unprefixedName());
		$this->assertSame('wp_reports', $table->name());

		$database->prefix = 'wp_2_';

		$this->assertSame('wp_2_reports', $table->name());
		$this->assertSame('SELECT * FROM `wp_2_reports` AS `r`', $table->query('r')->toSql());
	}

	public function test_it_provides_table_bound_write_operations(): void {
		$database                 = new FakeDatabase();
		$database->insertResult   = 1;
		$database->insertId       = 42;
		$database->executeResults = [2, 1];
		$table                    = new TestDatabaseTable('reports', $database);

		$this->assertSame(1, $table->insert(['status' => 'draft']));
		$this->assertSame(42, $table->insertGetId(['status' => 'draft']));
		$this->assertSame(2, $table->update(['status' => 'published'], ['id' => 42]));
		$this->assertSame(1, $table->delete(['id' => 42]));
		$this->assertSame([
			'INSERT wp_reports',
			'INSERT wp_reports',
			'UPDATE wp_reports',
			'DELETE wp_reports',
		], $database->executed);
	}

	public function test_subclasses_can_add_table_bound_operations_using_the_active_database_scope(): void {
		$database = new FakeDatabase();
		$table    = new TestDatabaseTable('reports', $database);

		$this->assertSame(1, $table->archiveByStatus('draft'));

		$database->prefix = 'wp_2_';

		$this->assertSame(1, $table->archiveByStatus('pending'));
		$this->assertSame([
			"UPDATE `wp_reports` SET status = 'archived' WHERE status = 'draft'",
			"UPDATE `wp_2_reports` SET status = 'archived' WHERE status = 'pending'",
		], $database->executed);
	}
}
