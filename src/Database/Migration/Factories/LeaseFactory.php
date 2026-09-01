<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Factories;

use StellarWP\Foundation\Database\Contracts\DatabaseScope;
use StellarWP\Foundation\Database\Migration\Lease;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\LockToken;

/**
 * Creates migration leases from operation-specific lock ownership.
 *
 * @internal Migration leases are created and owned by the migration store.
 */
final class LeaseFactory
{
	/**
	 * Create a lease for an acquired lock token and database scope.
	 */
	public function create(
		Lock $lock,
		DatabaseScope $scope,
		int $scopeId,
		LockToken $token,
		int $ttl
	): Lease {
		return new Lease($lock, $scope, $scopeId, $token, $ttl);
	}
}
