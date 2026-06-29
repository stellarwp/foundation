<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Identifier\Ulid;

use OutOfRangeException;
use RuntimeException;
use StellarWP\Foundation\Identifier\Contracts\IdentifierGenerator;
use StellarWP\Foundation\Identifier\Ulid\Contracts\UlidGenerator as UlidGeneratorContract;
use StellarWP\Foundation\Identifier\Ulid\UlidGenerator;
use StellarWP\Foundation\Identifier\Ulid\UlidValidator;
use StellarWP\Foundation\Tests\Support\Fixtures\Identifier\Ulid\FixedEntropy;
use StellarWP\Foundation\Tests\Support\Fixtures\Identifier\Ulid\FixedMillisecondClock;
use StellarWP\Foundation\Tests\TestCase;

final class UlidGeneratorTest extends TestCase
{
	public function test_it_is_an_identifier_generator(): void {
		$generator = new UlidGenerator(
			new FixedEntropy(str_repeat("\0", 10)),
			new FixedMillisecondClock(0)
		);

		$this->assertInstanceOf(IdentifierGenerator::class, $generator);
		$this->assertInstanceOf(UlidGeneratorContract::class, $generator);
	}

	public function test_it_generates_valid_ulids(): void {
		$identifier = (new UlidGenerator(
			new FixedEntropy(str_repeat("\0", 10)),
			new FixedMillisecondClock(0)
		))->generate();

		$this->assertSame(26, strlen($identifier));
		$this->assertTrue((new UlidValidator())->isValid($identifier));
	}

	public function test_it_encodes_the_timestamp_before_randomness(): void {
		$entropy = new FixedEntropy(str_repeat("\0", 10));

		$generator = new UlidGenerator(
			$entropy,
			new FixedMillisecondClock(1_469_918_176_385)
		);

		$this->assertSame('01ARYZ6S410000000000000000', $generator->generate());
		$this->assertSame([10], $entropy->requestedLengths());
	}

	public function test_it_accepts_the_maximum_ulid_timestamp(): void {
		$generator = new UlidGenerator(
			new FixedEntropy(str_repeat("\0", 10)),
			new FixedMillisecondClock(281_474_976_710_655)
		);

		$this->assertSame('7ZZZZZZZZZ0000000000000000', $generator->generate());
	}

	public function test_it_encodes_randomness(): void {
		$generator = new UlidGenerator(
			new FixedEntropy(str_repeat("\xff", 10)),
			new FixedMillisecondClock(0)
		);

		$this->assertSame('0000000000ZZZZZZZZZZZZZZZZ', $generator->generate());
	}

	public function test_it_rejects_timestamps_outside_the_ulid_range(): void {
		$this->expectException(OutOfRangeException::class);
		$this->expectExceptionMessage('ULID timestamps must be between 0 and 281474976710655 milliseconds.');

		(new UlidGenerator(
			new FixedEntropy(str_repeat("\0", 10)),
			new FixedMillisecondClock(-1)
		))->generate();
	}

	public function test_it_rejects_timestamps_above_the_ulid_range(): void {
		$this->expectException(OutOfRangeException::class);
		$this->expectExceptionMessage('ULID timestamps must be between 0 and 281474976710655 milliseconds.');

		(new UlidGenerator(
			new FixedEntropy(str_repeat("\0", 10)),
			new FixedMillisecondClock(281_474_976_710_656)
		))->generate();
	}

	public function test_it_rejects_entropy_that_does_not_return_ten_bytes(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('ULID generation requires exactly 10 random bytes.');

		(new UlidGenerator(
			new FixedEntropy(str_repeat("\0", 9)),
			new FixedMillisecondClock(0)
		))->generate();
	}
}
