<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration;

use StellarWP\Foundation\Database\Migration\Collection;
use StellarWP\Foundation\Database\Migration\Exceptions\DuplicateMigration;
use StellarWP\Foundation\Database\Migration\Exceptions\InvalidMigrationId;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestMigration;
use StellarWP\Foundation\Tests\TestCase;

final class CollectionTest extends TestCase
{
	public function test_it_orders_migrations_by_their_byte_exact_identifiers(): void {
		$first  = new TestMigration('2026_01_01_000001_create_users');
		$second = new TestMigration('2026_01_01_000002_create_posts');
		$third  = new TestMigration('2026_01_01_000003_create_comments');

		$collection = new Collection([$third, $first]);
		$collection->add($second);

		$indexed = [
			$first->id()  => $first,
			$second->id() => $second,
			$third->id()  => $third,
		];

		$this->assertSame($indexed, $collection->all());
		$this->assertSame([$first, $second, $third], $collection->values());
		$this->assertSame($indexed, iterator_to_array($collection));
	}

	public function test_explicit_custom_ids_are_ordered_lexically(): void {
		$last       = new TestMigration('reports_020_add_status');
		$first      = new TestMigration('reports_010_create_table');
		$collection = new Collection([$last, $first]);

		$this->assertSame([$first, $last], $collection->values());
	}

	public function test_it_rejects_duplicate_migration_ids(): void {
		$this->expectException(DuplicateMigration::class);

		new Collection([
			new TestMigration('2026_01_01_000001_create_users'),
			new TestMigration('2026_01_01_000001_create_users'),
		]);
	}

	public function test_a_rejected_addition_does_not_partially_mutate_the_collection(): void {
		$existing   = new TestMigration('2026_01_01_000002_existing');
		$collection = new Collection([$existing]);

		try {
			$collection->add(
				new TestMigration('2026_01_01_000001_new'),
				new TestMigration($existing->id())
			);
			$this->fail('Expected the duplicate migration to be rejected.');
		} catch (DuplicateMigration) {
			$this->assertSame([$existing], $collection->values());
		}
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
