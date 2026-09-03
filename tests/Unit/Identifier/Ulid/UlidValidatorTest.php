<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Identifier\Ulid;

use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Identifier\Ulid\UlidValidator;
use StellarWP\Foundation\Tests\TestCase;

final class UlidValidatorTest extends TestCase
{
	/**
	 * @return array<string,array{identifier: string}>
	 */
	public static function invalidUlidProvider(): array {
		return [
			'empty'           => ['identifier' => ''],
			'too short'       => ['identifier' => '01ARYZ6S41000000000000000'],
			'too long'        => ['identifier' => '01ARYZ6S4100000000000000000'],
			'lowercase'       => ['identifier' => '01aryz6s410000000000000000'],
			'ambiguous i'     => ['identifier' => '01ARYZ6S41000000000000000I'],
			'ambiguous l'     => ['identifier' => '01ARYZ6S41000000000000000L'],
			'ambiguous o'     => ['identifier' => '01ARYZ6S41000000000000000O'],
			'excluded u'      => ['identifier' => '01ARYZ6S41000000000000000U'],
			'timestamp above' => ['identifier' => '81ARYZ6S410000000000000000'],
		];
	}

	public function test_it_accepts_canonical_ulids(): void {
		$this->assertTrue((new UlidValidator())->isValid('01ARYZ6S410000000000000000'));
	}

	public function test_it_accepts_canonical_ulids_at_the_maximum_timestamp(): void {
		$this->assertTrue((new UlidValidator())->isValid('7ZZZZZZZZZ0000000000000000'));
	}

	/**
	 * @dataProvider invalidUlidProvider
	 */
	#[DataProvider('invalidUlidProvider')]
	public function test_it_rejects_invalid_ulids(string $identifier): void {
		$this->assertFalse((new UlidValidator())->isValid($identifier));
	}
}
