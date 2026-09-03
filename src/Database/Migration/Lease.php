<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use StellarWP\Foundation\Database\Contracts\DatabaseScope;
use StellarWP\Foundation\Database\Exceptions\DatabaseContextChanged;
use StellarWP\Foundation\Database\Exceptions\MigrationLockFailed;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\Lock\LockToken;

/**
 * Maintains migration lock ownership within the database scope where it was acquired.
 *
 * @internal Migration leases are created and owned by the migration store.
 */
final class Lease
{
	/**
	 * Track an acquired token and the database scope in which it is valid.
	 */
	public function __construct(
		private readonly Lock $lock,
		private readonly DatabaseScope $scope,
		private readonly int $scopeId,
		private LockToken $token,
		private readonly int $ttl
	) {
	}

	/**
	 * Extend the migration lock lease and retain its latest ownership token.
	 *
	 * @throws DatabaseContextChanged   When the active database scope has changed.
	 * @throws MigrationLockFailed      When lock ownership has been lost.
	 * @throws LockUnavailableException When the backend cannot determine the refresh result.
	 */
	public function renew(): void {
		$this->scope->assertCurrent($this->scopeId);
		$refreshed = $this->lock->refresh($this->token, $this->ttl);
		$this->scope->assertCurrent($this->scopeId);

		if ($refreshed === null) {
			throw MigrationLockFailed::forLostOwnership($this->token->name);
		}

		$this->token = $refreshed;
	}

	/**
	 * Release the migration lock using its latest ownership token.
	 *
	 * @throws DatabaseContextChanged   When the active database scope has changed.
	 * @throws MigrationLockFailed      When lock ownership cannot be confirmed.
	 * @throws LockUnavailableException When the backend cannot determine the release result.
	 */
	public function release(): void {
		$this->scope->assertCurrent($this->scopeId);
		$released = $this->lock->release($this->token);
		$this->scope->assertCurrent($this->scopeId);

		if (! $released) {
			throw MigrationLockFailed::forUnconfirmedOwnership($this->token->name);
		}
	}
}
