<?php declare(strict_types=1);

namespace StellarWP\Foundation\LockRedis\Contracts;

use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;

/**
 * Provides the atomic Redis operations required to coordinate leases.
 */
interface Connection
{
	/**
	 * Evaluate an atomic Redis script.
	 *
	 * @param list<string>     $keys
	 * @param list<string|int> $arguments
	 *
	 * @throws LockUnavailableException When Redis cannot determine the result.
	 */
	public function evaluate(string $script, array $keys, array $arguments): int;

	/**
	 * Determine whether the Redis key exists.
	 *
	 * @throws LockUnavailableException When Redis cannot determine the result.
	 */
	public function exists(string $key): bool;
}
