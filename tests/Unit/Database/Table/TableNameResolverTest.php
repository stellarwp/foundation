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
}
