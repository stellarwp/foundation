<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Schema\ValueObjects;

use StellarWP\Foundation\Database\Schema\ValueObjects\IndexState;
use StellarWP\Foundation\Database\Table\IndexType;
use StellarWP\Foundation\Tests\TestCase;

final class IndexStateTest extends TestCase
{
	public function test_it_compares_the_index_type_and_ordered_columns(): void {
		$expected = new IndexState('catalog_lookup', IndexType::KEY, ['catalog_id', 'status']);

		$this->assertTrue($expected->hasSameDefinitionAs(new IndexState('CATALOG_LOOKUP', IndexType::KEY, ['catalog_id', 'status'])));
		$this->assertFalse($expected->hasSameDefinitionAs(new IndexState('other_lookup', IndexType::KEY, ['catalog_id', 'status'])));
		$this->assertFalse($expected->hasSameDefinitionAs(new IndexState('catalog_lookup', IndexType::UNIQUE, ['catalog_id', 'status'])));
		$this->assertFalse($expected->hasSameDefinitionAs(new IndexState('catalog_lookup', IndexType::KEY, ['status', 'catalog_id'])));
	}
}
