<?php declare(strict_types=1);

namespace StellarWP\Foundation\Identifier\Ulid\Contracts;

/**
 * Supplies current time in milliseconds for ULID timestamps.
 */
interface MillisecondClock
{
	public function milliseconds(): int;
}
