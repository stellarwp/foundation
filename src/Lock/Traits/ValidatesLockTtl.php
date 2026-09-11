<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock\Traits;

use InvalidArgumentException;

/**
 * Enforces the shared positive TTL requirement for lock implementations.
 */
trait ValidatesLockTtl
{
	/**
	 * @throws InvalidArgumentException When the TTL is less than one second.
	 */
	private function assertValidLockTtl(int $ttl): void {
		if ($ttl < 1) {
			throw new InvalidArgumentException('Lock TTL must be greater than zero seconds.');
		}
	}
}
