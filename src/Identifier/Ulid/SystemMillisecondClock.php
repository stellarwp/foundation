<?php declare(strict_types=1);

namespace StellarWP\Foundation\Identifier\Ulid;

use StellarWP\Foundation\Identifier\Ulid\Contracts\MillisecondClock;

/**
 * Reads wall-clock time as Unix epoch milliseconds.
 */
final class SystemMillisecondClock implements MillisecondClock
{
	public function milliseconds(): int {
		return (int) floor(microtime(true) * 1000);
	}
}
