<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Query;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Query\Query;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestTable;
use StellarWP\Foundation\Tests\TestCase;

final class QueryBuilderTest extends TestCase
{
	public function test_it_builds_inspectable_select_queries(): void {
		$database = new FakeDatabase();
		$table    = new TestTable('reports_table', 'wp_reports');
		$query    = $database
			->table($table, 'r')
			->select('id', 'title')
			->where('status', '=', 'published')
			->orderBy('id', 'DESC')
			->limit(10, 5);

		$this->assertSame(
			'SELECT `id`, `title` FROM `wp_reports` AS `r` WHERE `status` = %s ORDER BY `id` DESC LIMIT %d OFFSET %d',
			$query->toSql()
		);
		$this->assertSame(['published', 10, 5], $query->bindings());
		$this->assertSame(
			"SELECT `id`, `title` FROM `wp_reports` AS `r` WHERE `status` = 'published' ORDER BY `id` DESC LIMIT 10 OFFSET 5",
			$query->toPreparedSql()
		);
	}

	public function test_it_rejects_unsupported_operators(): void {
		$this->expectException(InvalidArgumentException::class);

		(new FakeDatabase())->table('reports')->where('status', 'BETWEEN', ['a', 'z']);
	}

	public function test_it_rejects_invalid_order_directions(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Order direction must be ASC or DESC.');

		(new FakeDatabase())->table('reports')->orderBy('id', 'SIDEWAYS');
	}

	public function test_it_rejects_invalid_limits(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Query limit must be greater than zero.');

		(new FakeDatabase())->table('reports')->limit(0);
	}

	public function test_it_rejects_negative_offsets(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Query offset cannot be negative.');

		(new FakeDatabase())->table('reports')->limit(10, -1);
	}

	public function test_it_reads_the_first_row(): void {
		$database               = new FakeDatabase();
		$database->rowResults[] = ['name' => 'first'];

		$this->assertSame(
			['name' => 'first'],
			$database->table('reports')->where('id', '=', 1)->first()
		);
	}

	public function test_it_selects_all_columns_by_default(): void {
		$query = (new FakeDatabase())->table('reports');

		$this->assertSame('SELECT * FROM `wp_reports`', $query->toSql());
	}

	public function test_it_builds_query_objects(): void {
		$query = (new FakeDatabase())->table('reports')->where('id', '=', 10)->query();

		$this->assertInstanceOf(Query::class, $query);
		$this->assertSame('SELECT * FROM `wp_reports` WHERE `id` = %s', $query->toSql());
		$this->assertSame([10], $query->bindings());
	}
}
