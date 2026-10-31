<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Migrations;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StellarWP\Foundation\Migrations\Schema\TableBlueprint;

/**
 * Table declarations validate their names and required columns before schema translation.
 */
final class TableBlueprintTest extends TestCase
{
	/**
	 * Supply both supported index declarations.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function indexDeclarations(): iterable {
		yield 'secondary' => ['index'];

		yield 'unique' => ['unique'];
	}

	/**
	 * Reject an empty declaration at the Foundation boundary.
	 *
	 * @dataProvider indexDeclarations
	 */
	#[DataProvider('indexDeclarations')]
	public function test_an_index_requires_columns(string $method): void {
		$table = new TableBlueprint('entries');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('An index must contain at least one column.');
		$table->$method('lookup');
	}
	/**
	 * Reject blank and case-only rename destinations before schema translation.
	 *
	 * @param non-empty-string $from
	 * @param non-empty-string $to
	 *
	 * @dataProvider invalidRenameNames
	 */
	#[DataProvider('invalidRenameNames')]
	public function test_column_rename_requires_distinct_names(string $from, string $to): void {
		$this->expectException(InvalidArgumentException::class);
		(new TableBlueprint('entries'))->renameColumn($from, $to);
	}

	/**
	 * Supply invalid rename pairs, including MySQL's case-insensitive column identity.
	 *
	 * @return iterable<string, array{non-empty-string, non-empty-string}>
	 */
	public static function invalidRenameNames(): iterable {
		yield 'blank source' => [
			' ',
			'headline',
		];

		yield 'blank destination' => [
			'title',
			' ',
		];

		yield 'same name' => [
			'title',
			'title',
		];

		yield 'case-only rename' => [
			'title',
			'Title',
		];
	}
}
