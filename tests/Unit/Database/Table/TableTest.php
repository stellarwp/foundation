<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table;

use InvalidArgumentException;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestDatabaseTable;
use StellarWP\Foundation\Tests\TestCase;

final class TableTest extends TestCase
{
	public function test_it_resolves_the_current_database_scope_for_each_operation(): void {
		$database = new FakeDatabase();
		$table    = new TestDatabaseTable('reports', $database);

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

	public function test_it_rejects_an_invalid_unprefixed_table_name(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot be blank or contain surrounding whitespace');

		new TestDatabaseTable(' reports ', new FakeDatabase());
	}

	public function test_it_rejects_an_empty_unprefixed_table_name(): void {
		$this->expectException(InvalidArgumentException::class);

		new TestDatabaseTable('', new FakeDatabase());
	}
}
