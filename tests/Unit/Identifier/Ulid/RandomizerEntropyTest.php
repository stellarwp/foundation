<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Identifier\Ulid;

use Random\Engine\Secure;
use Random\Randomizer;
use StellarWP\Foundation\Identifier\Ulid\RandomizerEntropy;
use StellarWP\Foundation\Tests\TestCase;

final class RandomizerEntropyTest extends TestCase
{
	public function test_it_returns_the_requested_number_of_bytes(): void {
		$this->assertSame(10, strlen((new RandomizerEntropy(new Randomizer(new Secure())))->bytes(10)));
	}
}
