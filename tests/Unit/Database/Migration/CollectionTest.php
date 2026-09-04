<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration;

use StellarWP\Foundation\Database\Migration\Collection;
use StellarWP\Foundation\Database\Migration\Exceptions\DuplicateMigration;
use StellarWP\Foundation\Database\Migration\Exceptions\InvalidMigrationId;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestMigration;
use StellarWP\Foundation\Tests\TestCase;

final class CollectionTest extends TestCase
{
	public function test_it_collects_migrations_in_order(): void {
		$first  = new TestMigration('2026_01_01_000001_create_users');
		$second = new TestMigration('2026_01_01_000002_create_posts');

		$collection = new Collection([$first]);
		$collection->add($second);

		$indexed = [
			$first->id()  => $first,
			$second->id() => $second,
		];

		$this->assertSame($indexed, $collection->all());
		$this->assertSame([$first, $second], $collection->values());
		$this->assertSame($indexed, iterator_to_array($collection));
	}

	public function test_it_rejects_duplicate_migration_ids(): void {
		$this->expectException(DuplicateMigration::class);

		new Collection([
			new TestMigration('2026_01_01_000001_create_users'),
			new TestMigration('2026_01_01_000001_create_users'),
		]);
	}

	public function test_it_rejects_blank_migration_ids(): void {
		$this->expectException(InvalidMigrationId::class);

		new Collection([new TestMigration('   ')]);
	}

	public function test_it_rejects_padded_migration_ids(): void {
		$this->expectException(InvalidMigrationId::class);

		new Collection([new TestMigration(' migration')]);
	}

	public function test_it_rejects_migration_ids_larger_than_the_ledger_column(): void {
		$this->expectException(InvalidMigrationId::class);
		$this->expectExceptionMessage('cannot exceed 191 bytes');

		new Collection([new TestMigration(str_repeat('a', 192))]);
	}

	public function test_it_rejects_integer_like_migration_ids_that_php_would_coerce_to_array_keys(): void {
		$this->expectException(InvalidMigrationId::class);
		$this->expectExceptionMessage('cannot be integer-like strings');

		new Collection([new TestMigration('123')]);
	}

	public function test_it_accepts_case_distinct_ids_at_the_maximum_length(): void {
		$upper      = new TestMigration(str_repeat('A', 191));
		$lower      = new TestMigration(str_repeat('a', 191));
		$collection = new Collection([$upper, $lower]);

		$this->assertSame([
			$upper->id() => $upper,
			$lower->id() => $lower,
		], $collection->all());
		$this->assertSame([$upper, $lower], $collection->values());
	}
}
