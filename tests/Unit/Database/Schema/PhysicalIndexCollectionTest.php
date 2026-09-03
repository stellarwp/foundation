<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Schema\PhysicalIndexCollection;
use StellarWP\Foundation\Database\Schema\ValueObjects\IndexState;
use StellarWP\Foundation\Database\Table\IndexType;
use StellarWP\Foundation\Tests\TestCase;

final class PhysicalIndexCollectionTest extends TestCase
{
	public function test_it_normalizes_physical_indexes_for_comparison(): void {
		$indexes = PhysicalIndexCollection::fromRows([
			self::indexRow('PRIMARY', 0, 1, 'id'),
			self::indexRow('Catalog_Search', 1, 2, 'sku', 32, indexType: 'FULLTEXT', collation: 'D'),
			self::indexRow('Catalog_Search', 1, 1, 'title', indexType: 'FULLTEXT'),
		], 'wp_catalog')->all();

		$primary = $indexes['primary'];
		$search  = $indexes['catalog_search'];

		$this->assertSame('PRIMARY', $primary->name);
		$this->assertTrue($primary->hasSameDefinitionAs(new IndexState('PRIMARY', IndexType::PRIMARY, ['id'])));
		$this->assertSame('Catalog_Search', $search->name);
		$this->assertTrue($search->hasSameDefinitionAs(new IndexState('catalog_search', IndexState::FULLTEXT, ['title', 'sku(32) desc'])));
		$this->assertSame('FULLTEXT (title, sku(32) desc)', $search->describe());
		$this->assertSame([
			'primary'        => $primary,
			'catalog_search' => $search,
		], $indexes);
	}

	/**
	 * @dataProvider invalidMetadata
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	#[DataProvider('invalidMetadata')]
	public function test_it_rejects_invalid_physical_index_metadata(array $rows, string $message): void {
		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage($message);

		PhysicalIndexCollection::fromRows($rows, 'wp_catalog');
	}

	/**
	 * @return iterable<string, array{list<array<string, mixed>>, string}>
	 */
	public static function invalidMetadata(): iterable {
		yield 'missing row fields' => [
			[['Key_name' => 'PRIMARY']],
			'invalid index metadata for wp_catalog.',
		];

		yield 'inconsistent type' => [[
			self::indexRow('catalog', 1, 1, 'name'),
			self::indexRow('catalog', 0, 2, 'sku'),
		], 'invalid index metadata for wp_catalog.catalog.'];

		yield 'duplicate sequence' => [[
			self::indexRow('catalog', 1, 1, 'name'),
			self::indexRow('catalog', 1, 1, 'sku'),
		], 'invalid index metadata for wp_catalog.catalog.'];

		yield 'invalid collation' => [[
			self::indexRow('catalog', 1, 1, 'name', collation: 'X'),
		], 'invalid index metadata for wp_catalog.'];

		yield 'invalid uniqueness flag' => [[
			self::indexRow('catalog', 2, 1, 'name'),
		], 'invalid index metadata for wp_catalog.'];

		yield 'non-unique primary index' => [[
			self::indexRow('PRIMARY', 1, 1, 'id'),
		], 'invalid index metadata for wp_catalog.PRIMARY.'];

		yield 'invalid sequence' => [[
			self::indexRow('catalog', 1, 0, 'name'),
		], 'invalid index metadata for wp_catalog.'];

		yield 'invalid prefix length' => [[
			self::indexRow('catalog', 1, 1, 'name', 0),
		], 'invalid index metadata for wp_catalog.catalog.'];

		yield 'non-contiguous sequence' => [[
			self::indexRow('catalog', 1, 2, 'name'),
		], 'invalid index metadata for wp_catalog.catalog.'];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function indexRow(
		string $name,
		int $nonUnique,
		int $sequence,
		string $column,
		?int $subPart = null,
		string $indexType = 'BTREE',
		?string $collation = 'A'
	): array {
		return [
			'Key_name'     => $name,
			'Non_unique'   => $nonUnique,
			'Seq_in_index' => $sequence,
			'Column_name'  => $column,
			'Sub_part'     => $subPart,
			'Index_type'   => $indexType,
			'Collation'    => $collation,
		];
	}
}
