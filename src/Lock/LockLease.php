<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock;

use Closure;
use LogicException;
use StellarWP\Foundation\Lock\Exceptions\LockOwnershipLostException;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;

/**
 * Renews ownership during a callback managed by LockOperation.
 *
 * Obtain this handle from the callback; LockOperation owns its lifetime.
 */
final readonly class LockLease
{
	/**
	 * Connect the handle to its owning operation's renewal lifecycle.
	 *
	 * @internal Constructed only by the managed lock lifecycle.
	 *
	 * @param Closure(): void $renew
	 *
	 * @param-later-invoked-callable $renew
	 */
	public function __construct(
		private Closure $renew
	) {
	}

	/**
	 * Renew ownership for the original TTL before the next bounded stage.
	 *
	 * A failed renewal is terminal: later attempts rethrow the first failure,
	 * and the operation cannot succeed even if its callback catches that failure.
	 * A closed lease cannot renew or reacquire ownership.
	 *
	 * @throws LockOwnershipLostException When ownership expired or changed.
	 * @throws LockUnavailableException   When the backend cannot determine the renewal result.
	 * @throws LogicException             When the owning operation has closed.
	 */
	public function renew(): void {
		($this->renew)();
	}
}
