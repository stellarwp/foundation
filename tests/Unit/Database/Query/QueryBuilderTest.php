<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Query;

use InvalidArgumentException;
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
}
