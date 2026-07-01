<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock\Contracts;

use InvalidArgumentException;
use StellarWP\Foundation\Lock\LockToken;

/**
 * Coordinates named locks with expiring ownership tokens.
 */
interface Lock
{
	/**
	 * Attempt to acquire a named lock for the given number of seconds.
	 *
	 * Returns a token when ownership was acquired, or null when another owner
	 * already holds the lock. Implementations that coordinate multiple
	 * processes should perform acquisition atomically.
	 *
	 * @throws InvalidArgumentException When the lock name is empty or the TTL
	 *                                  is less than one second.
	 */
	public function acquire(string $name, int $ttl): ?LockToken;

	/**
	 * Release a lock only when the token still owns it.
	 *
	 * Implementations that coordinate multiple processes should compare and
	 * release atomically by lock name, owner, and non-expired state.
	 */
	public function release(LockToken $token): bool;

	/**
	 * Renew a lock to a new expiration of now plus the given TTL.
	 *
	 * Returns the refreshed ownership token, or null when the lock is no
	 * longer owned by the provided token. Implementations that coordinate
	 * multiple processes should compare and renew atomically by lock name,
	 * owner, and non-expired state.
	 *
	 * @throws InvalidArgumentException When the TTL is less than one second.
	 */
	public function refresh(LockToken $token, int $ttl): ?LockToken;

	/**
	 * Determine whether a non-expired owner currently holds the named lock.
	 *
	 * This method is advisory only and should not be used as a check-then-act
	 * coordination primitive.
	 *
	 * @throws InvalidArgumentException When the lock name is empty.
	 */
	public function isAcquired(string $name): bool;
}
