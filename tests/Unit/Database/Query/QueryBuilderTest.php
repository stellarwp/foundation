<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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

	public function test_it_quotes_qualified_columns_and_select_wildcards_by_segment(): void {
		$query = (new FakeDatabase())
			->table('posts', 'p')
			->select('*', 'p.ID', 'p.*')
			->where('p.status', '=', 'publish')
			->where('p.deleted_at', '=', null)
			->orderBy('p.ID');

		$this->assertSame(
			'SELECT *, `p`.`ID`, `p`.* FROM `wp_posts` AS `p` WHERE `p`.`status` = %s AND `p`.`deleted_at` IS NULL ORDER BY `p`.`ID` ASC',
			$query->toSql()
		);
		$this->assertSame(['publish'], $query->bindings());
	}

	public function test_it_escapes_each_qualified_column_segment(): void {
		$query = (new FakeDatabase())->table('posts')->where('p`ost.I`D', '=', 1);

		$this->assertSame('SELECT * FROM `wp_posts` WHERE `p``ost`.`I``D` = %s', $query->toSql());
	}

	/**
	 * @dataProvider invalidColumnProvider
	 */
	#[DataProvider('invalidColumnProvider')]
	public function test_it_rejects_invalid_qualified_columns(string $column): void {
		$this->expectException(InvalidArgumentException::class);

		(new FakeDatabase())->table('posts')->select($column)->toSql();
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalidColumnProvider(): iterable {
		yield 'empty segment' => ['p..ID'];

		yield 'non-terminal wildcard' => ['p.*.ID'];
	}

	public function test_it_rejects_wildcards_outside_selects(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid query column wildcard');

		(new FakeDatabase())->table('posts')->where('p.*', '=', 1);
	}

	public function test_it_rejects_unsupported_operators(): void {
		$this->expectException(InvalidArgumentException::class);

		(new FakeDatabase())->table('reports')->where('status', 'BETWEEN', ['a', 'z']);
	}

	public function test_it_builds_null_comparisons_without_bindings(): void {
		$query = (new FakeDatabase())
			->table('reports')
			->where('deleted_at', '=', null)
			->where('archived_at', '!=', null)
			->where('expired_at', '<>', null)
			->where('status', '=', 'published');

		$this->assertSame(
			'SELECT * FROM `wp_reports` WHERE `deleted_at` IS NULL AND `archived_at` IS NOT NULL AND `expired_at` IS NOT NULL AND `status` = %s',
			$query->toSql()
		);
		$this->assertSame(['published'], $query->bindings());
	}

	public function test_it_rejects_invalid_null_comparisons(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('NULL comparisons only support =, !=, and <> operators.');

		(new FakeDatabase())->table('reports')->where('updated_at', '>', null);
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
		$this->assertStringEndsWith('LIMIT 1', $database->rowQueries[0]);
	}

	public function test_reading_the_first_row_does_not_mutate_the_builder_and_preserves_its_offset(): void {
		$database               = new FakeDatabase();
		$database->rowResults[] = ['name' => 'sixth'];
		$query                  = $database->table('reports')->limit(25, 5);

		$this->assertSame(['name' => 'sixth'], $query->first());
		$this->assertStringEndsWith('LIMIT 1 OFFSET 5', $database->rowQueries[0]);
		$this->assertSame('SELECT * FROM `wp_reports` LIMIT %d OFFSET %d', $query->toSql());
		$this->assertSame([25, 5], $query->bindings());
	}

	public function test_it_selects_all_columns_by_default(): void {
		$query = (new FakeDatabase())->table('reports');

		$this->assertSame('SELECT * FROM `wp_reports`', $query->toSql());
	}

	public function test_it_builds_query_objects(): void {
		$query = (new FakeDatabase())->table('reports')->where('id', '=', 10)->toQuery();

		$this->assertInstanceOf(Query::class, $query);
		$this->assertSame('SELECT * FROM `wp_reports` WHERE `id` = %s', $query->toSql());
		$this->assertSame([10], $query->bindings());
	}

	public function test_it_returns_the_maximum_value_without_mutating_the_builder(): void {
		$database               = new FakeDatabase();
		$database->rowResults[] = ['maximum' => '4'];
		$query                  = $database->table('reports')
			->where('status', '=', 'complete')
			->orderBy('batch')
			->limit(10, 5);

		$this->assertSame('4', $query->max('batch'));
		$this->assertSame(
			"SELECT MAX(`batch`) FROM `wp_reports` WHERE `status` = 'complete'",
			$database->rowQueries[0]
		);
		$this->assertSame(
			'SELECT * FROM `wp_reports` WHERE `status` = %s ORDER BY `batch` ASC LIMIT %d OFFSET %d',
			$query->toSql()
		);
		$this->assertSame(['complete', 10, 5], $query->bindings());
	}
}
