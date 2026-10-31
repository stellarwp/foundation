<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock;

use InvalidArgumentException;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\Exceptions\LockContendedException;
use StellarWP\Foundation\Lock\Exceptions\LockOwnershipLostException;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use Throwable;

/**
 * Runs bounded work while the application's selected lock is owned.
 */
final readonly class LockOperation
{
	/**
	 * Use the application's selected lock implementation.
	 */
	public function __construct(
		private Lock $lock
	) {
	}

	/**
	 * Return the callback's result after releasing its owned lock.
	 *
	 * Makes one acquisition attempt, without waiting or retrying. The callback
	 * receives a lease it may renew between bounded stages using the original TTL.
	 * Any renewal failure is terminal, even if the callback catches it and returns.
	 * An escaping callback exception takes precedence over renewal and cleanup
	 * failures; otherwise a recorded renewal failure takes precedence over cleanup.
	 *
	 * Successful release confirms ownership at release, not transactional work.
	 * Renewal cannot interrupt work that outlives the lease.
	 *
	 * @template TResult
	 *
	 * @param string                       $name      The resource name to coordinate across lock owners.
	 * @param int                          $ttl       The lease duration in whole seconds; it must be at least one.
	 * @param callable(LockLease): TResult $operation
	 *
	 * @param-immediately-invoked-callable $operation
	 *
	 * @throws InvalidArgumentException   When the name or TTL is invalid for the selected backend.
	 * @throws LockContendedException     When acquisition is contended, or the callback propagates contention.
	 * @throws LockOwnershipLostException When renewal or release cannot confirm ownership.
	 * @throws LockUnavailableException   When the backend cannot determine an ownership result.
	 * @throws Throwable                  When the operation throws.
	 *
	 * @return TResult
	 */
	public function run(string $name, int $ttl, callable $operation): mixed {
		$token = $this->lock->acquire($name, $ttl);

		if ($token === null) {
			throw new LockContendedException(sprintf('Lock "%s" is already owned.', $name));
		}

		$execution = new LockExecution($this->lock, $token, $ttl);

		return $execution->run($operation);
	}
}
