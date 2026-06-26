<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration;

use StellarWP\Foundation\Database\Exceptions\DuplicateMigration;
use StellarWP\Foundation\Database\Migration\Collection;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestMigration;
use StellarWP\Foundation\Tests\TestCase;

final class CollectionTest extends TestCase
{
	public function test_it_collects_migrations_in_order(): void {
		$first  = new TestMigration('2026_01_01_000001_create_users');
		$second = new TestMigration('2026_01_01_000002_create_posts');

		$collection = new Collection([$first]);
		$collection->add($second);

		$this->assertSame([$first, $second], $collection->all());
		$this->assertSame([$first, $second], iterator_to_array($collection));
	}

	public function test_it_rejects_duplicate_migration_ids(): void {
		$this->expectException(DuplicateMigration::class);

		new Collection([
			new TestMigration('2026_01_01_000001_create_users'),
			new TestMigration('2026_01_01_000001_create_users'),
		]);
	}
}
