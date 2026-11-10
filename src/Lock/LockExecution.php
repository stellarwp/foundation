<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock;

use LogicException;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\Exceptions\LockOwnershipLostException;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use Throwable;

/**
 * Owns the mutable lifetime of one acquired lock operation.
 *
 * @internal Created for one LockOperation invocation.
 */
final class LockExecution
{
	private ?Throwable $failure = null;
	private bool $closed        = false;

	/**
	 * Take ownership of the acquired token until callback cleanup completes.
	 */
	public function __construct(
		private readonly Lock $lock,
		private LockToken $token,
		private readonly int $ttl
	) {
	}

	/**
	 * Execute the callback and release ownership, preserving the primary failure.
	 *
	 * @template TResult
	 *
	 * @param callable(LockLease): TResult $operation
	 *
	 * @param-immediately-invoked-callable $operation
	 *
	 * @throws LockOwnershipLostException When renewal or release cannot confirm ownership.
	 * @throws LockUnavailableException   When the backend cannot determine an ownership result.
	 * @throws Throwable                  When the callback throws.
	 *
	 * @return TResult
	 */
	public function run(callable $operation): mixed {
		try {
			$result = $operation(new LockLease($this->renew(...)));

			if ($this->failure !== null) {
				throw $this->failure;
			}

			return $result;
		} catch (Throwable $operationFailure) {
			$this->failure = $operationFailure;

			throw $operationFailure;
		} finally {
			$this->closed = true;

			try {
				if (! $this->lock->release($this->token)) {
					throw new LockOwnershipLostException('Protected work lost lock ownership.');
				}
			} catch (Throwable $cleanupFailure) {
				if ($this->failure === null) {
					throw $cleanupFailure;
				}
			}
		}
	}

	private function renew(): void {
		if ($this->closed) {
			throw new LogicException('The lock operation has already closed.');
		}

		if ($this->failure !== null) {
			throw $this->failure;
		}

		try {
			$refreshed = $this->lock->refresh($this->token, $this->ttl);

			if ($refreshed === null) {
				throw new LockOwnershipLostException('Protected work lost lock ownership.');
			}

			$this->token = $refreshed;
		} catch (Throwable $renewalFailure) {
			$this->failure = $renewalFailure;

			throw $renewalFailure;
		}
	}
}
