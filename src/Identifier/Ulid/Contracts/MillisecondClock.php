<?php declare(strict_types=1);

namespace StellarWP\Foundation\Identifier\Ulid\Contracts;

/**
 * Supplies current time in milliseconds for ULID timestamps.
 */
interface MillisecondClock
{
	/**
	 * Return the current Unix timestamp in milliseconds.
	 */
	public function milliseconds(): int;
}
