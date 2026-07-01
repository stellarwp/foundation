<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Query;

use StellarWP\Foundation\Database\Query\Query;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\TestCase;

final class QueryTest extends TestCase
{
	public function test_it_exposes_sql_bindings_and_prepared_sql(): void {
		$database = new FakeDatabase();
		$query    = new Query($database, 'SELECT * FROM %i WHERE status = %s', ['wp_reports', 'published']);

		$this->assertSame('SELECT * FROM %i WHERE status = %s', $query->toSql());
		$this->assertSame(['wp_reports', 'published'], $query->bindings());
		$this->assertSame("SELECT * FROM `wp_reports` WHERE status = 'published'", $query->toPreparedSql());
	}

	public function test_it_executes_rows_first_and_value_queries(): void {
		$database = new FakeDatabase();
		$query    = new Query($database, 'SELECT name FROM %i WHERE id = %d', ['wp_reports', 1]);

		$database->rowsResults[] = [['name' => 'first']];
		$database->rowResults[]  = ['name' => 'first'];
		$database->rowResults[]  = ['count' => 3];

		$this->assertSame([['name' => 'first']], $query->get());
		$this->assertSame(['name' => 'first'], $query->first());
		$this->assertSame(3, $query->value());
	}
}
