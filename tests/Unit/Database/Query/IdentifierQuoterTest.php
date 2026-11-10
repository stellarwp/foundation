<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StellarWP\Foundation\Database\Query\IdentifierQuoter;

final class IdentifierQuoterTest extends TestCase
{
	public function test_qualified_selections_and_aliases_quote_each_name(): void {
		$quoter = new IdentifierQuoter();
		$this->assertSame('`e`.`name` AS `display_name`', $quoter->selection('e.name AS display_name')->fragment->sql);
		$this->assertSame('`e`.*', $quoter->selection('e.*')->fragment->sql);
		$this->assertSame('*', $quoter->selection('*')->fragment->sql);
		$this->assertSame('`2_entries`', $quoter->quote('2_entries'));
	}

	public function test_selections_keep_sql_output_names_and_wildcards_together(): void {
		$quoter = new IdentifierQuoter();
		$alias  = $quoter->selection("e.Name\tAs\tDisplay_Name");
		$this->assertSame('`e`.`Name` AS `Display_Name`', $alias->fragment->sql);
		$this->assertSame('display_name', $alias->name);
		$this->assertFalse($alias->wildcard);
		$this->assertSame('name', $quoter->selection('e.Name')->name);

		foreach ([
			'*',
			'e.*',
		] as $column) {
			$selection = $quoter->selection($column);
			$this->assertSame('*', $selection->name);
			$this->assertTrue($selection->wildcard);
		}
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalidColumns(): iterable {
		yield 'blank' => [
			'',
		];

		yield 'expression' => [
			'name DESC; DROP TABLE entries',
		];

		yield 'embedded quote' => [
			'name`',
		];

		yield 'database qualified' => [
			'db.table.column',
		];

		yield 'missing column' => [
			'e.',
		];

		yield 'wildcard predicate' => [
			'e.*',
		];

		yield 'sql comment' => [
			'id--',
		];
	}

	/**
	 * @dataProvider invalidColumns
	 */
	#[DataProvider('invalidColumns')]
	public function test_identifiers_reject_sql_syntax(string $column): void {
		$this->expectException(InvalidArgumentException::class);
		(new IdentifierQuoter())->column($column);
	}

	public function test_wildcards_cannot_have_output_aliases(): void {
		$this->expectException(InvalidArgumentException::class);
		(new IdentifierQuoter())->selection('e.* as output');
	}
}
