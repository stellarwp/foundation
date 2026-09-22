<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StellarWP\Foundation\Database\Migration\Schema\TableBlueprint;

/**
 * Index declarations must identify at least one column before schema translation.
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
}
