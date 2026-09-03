<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Database\Table\Index;
use StellarWP\Foundation\Database\Table\IndexType;
use StellarWP\Foundation\Tests\TestCase;

final class IndexTest extends TestCase
{
	public function test_it_exposes_the_declared_index_behavior_without_public_state_comparisons(): void {
		$primary = new Index('primary', ['id'], IndexType::PRIMARY);
		$unique  = new Index('email', ['email'], IndexType::UNIQUE);
		$key     = new Index('status', ['status']);

		$this->assertTrue($primary->isPrimary());
		$this->assertFalse($primary->isUnique());
		$this->assertFalse($unique->isPrimary());
		$this->assertTrue($unique->isUnique());
		$this->assertFalse($key->isPrimary());
		$this->assertFalse($key->isUnique());
	}

	/**
	 * @dataProvider invalidTypeProvider
	 */
	#[DataProvider('invalidTypeProvider')]
	public function test_it_rejects_unknown_index_types(string $type): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(sprintf('Unsupported index type "%s".', $type));

		new Index('lookup', ['status'], $type);
	}

	public function test_it_rejects_a_blank_index_name(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Index name cannot be blank.');

		new Index(' ', ['status']);
	}

	public function test_it_rejects_an_empty_column_list(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('An index must define at least one column.');

		new Index('status_lookup', []);
	}

	public function test_it_rejects_blank_column_names(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Index status_lookup contains a blank column name.');

		new Index('status_lookup', ['status', ' ']);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalidTypeProvider(): iterable {
		yield 'blank' => [''];

		yield 'unknown' => ['hash'];
	}
}
