<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock\Traits;

use DateInterval;
use DateMalformedIntervalStringException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Calculates local lock expiration times from a starting time and TTL.
 */
trait CalculatesLockExpiration
{
	/**
	 * @throws InvalidArgumentException When the TTL cannot be represented.
	 */
	private function calculateLockExpiration(DateTimeImmutable $startedAt, int $ttl): DateTimeImmutable {
		try {
			return $startedAt->add(new DateInterval(sprintf('PT%dS', $ttl)));
		} catch (DateMalformedIntervalStringException $exception) {
			throw new InvalidArgumentException('Lock TTL cannot be represented.', 0, $exception);
		}
	}
}
