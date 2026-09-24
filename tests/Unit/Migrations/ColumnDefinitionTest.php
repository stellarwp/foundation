<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Migrations;

use Doctrine\DBAL\Schema\Exception\InvalidTableModification;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StellarWP\Foundation\Migrations\Schema\ColumnDefinition;

/**
 * Decimal defaults must be normalized to the column scale without passing through floating point.
 */
final class ColumnDefinitionTest extends TestCase
{
	/**
	 * @return iterable<string, array{string, int, string}>
	 */
	public static function decimalDefaults(): iterable {
		yield 'pads zero to scale' => ['0', 4, '0.0000'];

		yield 'pads fraction' => ['1.5', 4, '1.5000'];

		yield 'negative integer' => ['-3', 2, '-3.00'];

		yield 'exact large value survives' => ['9007199254740993.01', 2, '9007199254740993.01'];

		yield 'excess precision is left for the comparator to surface' => ['1.23456', 4, '1.23456'];

		yield 'scale zero drops the point' => ['12', 0, '12'];
	}

	/**
	 * @dataProvider decimalDefaults
	 */
	#[DataProvider('decimalDefaults')]
	public function test_decimal_defaults_are_normalized_as_strings(string $declared, int $scale, string $expected): void {
		$table = Table::editor()->setUnquotedName('t');
		(new ColumnDefinition('amount', Types::DECIMAL, ['precision' => 20, 'scale' => $scale]))->default($declared)->applyTo($table);

		$this->assertSame($expected, $table->create()->getColumn('amount')->getDefault());
	}

	public function test_change_replaces_type_default_and_comment(): void {
		$table = Table::editor()->setUnquotedName('t');
		$table->addColumn(\Doctrine\DBAL\Schema\Column::editor()->setUnquotedName('value')->setTypeName(Types::STRING)->setLength(20)->setDefaultValue('x')->setComment('old')->create());

		(new ColumnDefinition('value', Types::INTEGER))->change()->applyTo($table);

		$column = $table->create()->getColumn('value');
		$this->assertSame(Types::INTEGER, Type::lookupName($column->getType()));
		$this->assertNull($column->getDefault());
		$this->assertSame('', $column->getComment());
		$this->assertTrue($column->getNotnull());
	}

	public function test_change_on_a_missing_column_fails_instead_of_adding_one(): void {
		$table = Table::editor()->setUnquotedName('t');
		$table->addColumn(\Doctrine\DBAL\Schema\Column::editor()->setUnquotedName('quantity')->setTypeName(Types::INTEGER)->create());

		$this->expectException(InvalidTableModification::class);
		(new ColumnDefinition('quanity', Types::INTEGER))->change()->applyTo($table);
	}

	public function test_explicit_zero_precision_matches_omitted_precision(): void {
		$explicit = Table::editor()->setUnquotedName('t');
		(new ColumnDefinition('created_at', Types::DATETIME_MUTABLE, [], 0))->useCurrent()->applyTo($explicit);
		$omitted = Table::editor()->setUnquotedName('t');
		(new ColumnDefinition('created_at', Types::DATETIME_MUTABLE))->useCurrent()->applyTo($omitted);

		$this->assertSame('CURRENT_TIMESTAMP', $explicit->create()->getColumn('created_at')->getDefault());
		$this->assertSame($omitted->create()->getColumn('created_at')->toArray(), $explicit->create()->getColumn('created_at')->toArray());
	}
}
