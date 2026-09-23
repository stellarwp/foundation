<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StellarWP\Foundation\Database\Contracts\DatabaseScope;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Table\TableNameResolver;

final class TableNameResolverTest extends TestCase
{
	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function invalidNames(): iterable {
		yield 'blank' => ['', 'wp_'];

		yield 'padded' => [' entries', 'wp_'];

		yield 'sql syntax' => ['entries;DROP', 'wp_'];

		yield 'qualified' => ['other.entries', 'wp_'];

		yield 'physical limit includes prefix' => [str_repeat('a', 63), 'wp_'];
	}

	/**
	 * @dataProvider invalidNames
	 */
	#[DataProvider('invalidNames')]
	public function test_invalid_names_fail_before_sql(string $name, string $prefix): void {
		$scope = $this->createMock(DatabaseScope::class);
		$scope->method('resolveTableName')->willReturn($prefix . $name);
		$table = $this->createMock(Table::class);
		$table->method('unprefixedName')->willReturn($name);
		$this->expectException(DatabaseException::class);
		(new TableNameResolver($scope))->tableName($table);
	}
	/**
	 * @dataProvider invalidNames
	 */
	#[DataProvider('invalidNames')]
	public function test_historical_string_names_have_the_same_validation(string $name, string $prefix): void {
		$scope = $this->createMock(DatabaseScope::class);
		$scope->method('resolveTableName')->willReturn($prefix . $name);
		$this->expectException(DatabaseException::class);
		(new TableNameResolver($scope))->tableName($name);
	}

	public function test_a_historical_name_is_resolved_again_for_each_site(): void {
		$scope = $this->createMock(DatabaseScope::class);
		$scope->expects(self::exactly(2))->method('resolveTableName')->with('reports')->willReturnOnConsecutiveCalls('wp_reports', 'wp_2_reports');
		$names = new TableNameResolver($scope);
		self::assertSame('wp_reports', $names->tableName('reports'));
		self::assertSame('wp_2_reports', $names->tableName('reports'));
	}
}
