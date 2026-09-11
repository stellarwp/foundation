<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock;

use DateTimeImmutable;
use StellarWP\Foundation\Lock\Contracts\Clock;

/**
 * Reads time from PHP's system clock.
 */
final class SystemClock implements Clock
{
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable();
	}
}
