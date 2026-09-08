<?php declare(strict_types=1);

namespace StellarWP\Foundation\Identifier\Ulid\Contracts;

use Random\RandomException;

/**
 * Supplies random bytes for ULID randomness.
 */
interface Entropy
{
	/**
	 * Return the requested number of random bytes for ULID generation.
	 *
	 * @throws RandomException When secure random bytes cannot be generated.
	 */
	public function bytes(int $length): string;
}
