<?php declare(strict_types=1);

namespace StellarWP\Foundation\Identifier\Ulid;

use Random\Randomizer;
use StellarWP\Foundation\Identifier\Ulid\Contracts\Entropy;

/**
 * Supplies cryptographically secure random bytes through PHP's random extension API.
 */
final readonly class RandomizerEntropy implements Entropy
{
	public function __construct(
		private Randomizer $randomizer
	) {
	}

	public function bytes(int $length): string {
		return $this->randomizer->getBytes($length);
	}
}
