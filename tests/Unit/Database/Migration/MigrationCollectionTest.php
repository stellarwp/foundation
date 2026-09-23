<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Migration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StellarWP\Foundation\Database\Migration\Contracts\Migration;
use StellarWP\Foundation\Database\Migration\Exceptions\InvalidMigrationId;
use StellarWP\Foundation\Database\Migration\MigrationCollection;
use StellarWP\Foundation\Database\Migration\Schema\Blueprint;
use StellarWP\Foundation\Database\Migration\ValueObjects\MigrationRegistration;

final class MigrationCollectionTest extends TestCase
{
	private function migration(string $id): MigrationRegistration {
		return new MigrationRegistration($id, new class implements Migration {
			public function up(Blueprint $schema): void {
			}
			public function down(Blueprint $schema): void {
			}
		});
	}

	public function test_order_and_identity_are_byte_exact_even_for_numeric_ids(): void {
		$ids           = ['a', '10', '2', '01', 'A', '20260922000100'];
		$registrations = array_map($this->migration(...), $ids);
		$collection    = new MigrationCollection($registrations);
		self::assertSame(['01', '10', '2', '20260922000100', 'A', 'a'], $collection->ids());
		foreach ($registrations as $registration) {
			self::assertSame($registration->migration, $collection->get($registration->id));
		}
		self::assertFalse($collection->has('missing'));
	}

	public function test_duplicate_ids_fail_before_execution(): void {
		$this->expectException(\InvalidArgumentException::class);
		new MigrationCollection([$this->migration('123'), $this->migration('123')]);
	}

	/** @return iterable<string, array{string}> */
	public static function invalidIds(): iterable {
		yield 'blank' => [''];

		yield 'padded' => [' id'];

		yield 'too long' => [str_repeat('x', 192)];

		yield 'none' => ['0'];

		yield 'latest' => ['latest'];
	}

	/**
	 * @dataProvider invalidIds
	 */
	#[DataProvider('invalidIds')]
	public function test_invalid_ids_fail_at_collection_boundary(string $id): void {
		$this->expectException(InvalidMigrationId::class);
		new MigrationCollection([$this->migration($id)]);
	}
}
