<?php declare(strict_types=1);

namespace StellarWP\Foundation\Identifier\Ulid\Contracts;

/**
 * Supplies random bytes for ULID randomness.
 */
interface Entropy
{
	public function bytes(int $length): string;
}
