<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock;

use InvalidArgumentException;
use StellarWP\Foundation\Lock\Contracts\Lock;
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
	 * Run an operation once ownership is acquired, or return false on contention.
	 *
	 * The operation receives no arguments and its return value is ignored. This method
	 * makes one acquisition attempt and never waits for, renews, or retries a lock.
	 *
	 * @param string            $name      The resource name to coordinate across lock owners.
	 * @param int               $ttl       The lease duration in whole seconds; it must be at least one.
	 * @param callable(): mixed $operation
	 *
	 * @throws InvalidArgumentException   When the name or TTL is invalid for the selected backend.
	 * @throws LockOwnershipLostException When successful work cannot confirm its release.
	 * @throws LockUnavailableException   When the backend cannot determine an ownership result.
	 * @throws Throwable                  When the operation throws.
	 */
	public function run(string $name, int $ttl, callable $operation): bool {
		$token = $this->lock->acquire($name, $ttl);

		if ($token === null) {
			return false;
		}

		try {
			$operation();
		} catch (Throwable $failure) {
			try {
				$this->lock->release($token);
			} catch (Throwable) {
				// Preserve the operation failure when cleanup also fails.
			}

			throw $failure;
		}

		if (! $this->lock->release($token)) {
			throw new LockOwnershipLostException('Protected work lost lock ownership.');
		}

		return true;
	}
}
