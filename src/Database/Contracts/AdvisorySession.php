<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use Closure;
use StellarWP\Foundation\Database\Exceptions\AdvisoryLockContended;
use StellarWP\Foundation\Database\Exceptions\AdvisoryLockInterrupted;
use Throwable;

/**
 * Keep database work on one site and connection while holding a session-owned advisory lock.
 *
 * Implementations must share failure tracking with the application's database connection.
 * A caught execution failure remains terminal until the locked operation ends. Nested
 * transactions may finish without releasing the advisory lock or clearing its failure.
 */
interface AdvisorySession
{
	/**
	 * Acquire once without waiting and release after the callback, preserving escaping failures.
	 *
	 * Reject entry from an existing transaction or locked operation. Check ownership before
	 * database execution and callback completion. Never reconnect or replay work while locked.
	 * Cleanup uses the original session and discards it when release cannot be confirmed.
	 *
	 * @template T
	 *
	 * @param string       $resource  Stable resource identity, scoped to the active database.
	 * @param Closure(): T $operation
	 *
	 * @throws AdvisoryLockContended   When another session owns the resource; the callback is not invoked.
	 * @throws AdvisoryLockInterrupted When locked work failed or ownership cannot be confirmed.
	 * @throws Throwable               When acquisition, the callback, or cleanup fails.
	 *
	 * @return T
	 */
	public function withAdvisoryLock(string $resource, Closure $operation): mixed;

	/**
	 * Confirm the original site, connection, lock ownership, and absence of terminal failure.
	 *
	 * @throws AdvisoryLockInterrupted When locked work cannot safely continue.
	 * @throws Throwable               When the shared database session has failed.
	 */
	public function check(): void;
}
