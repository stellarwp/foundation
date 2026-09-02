<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Factories;

use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Migration\Lease;
use StellarWP\Foundation\Database\Migration\Session;

/**
 * Creates migration sessions for a supplied schema and lock lease.
 *
 * @internal Migration sessions are created and owned by the migration store.
 */
final class SessionFactory
{
	/**
	 * Create a session for schema changes protected by an active migration lease.
	 */
	public function create(Schema $schema, Lease $lease): Session {
		return new Session($schema, $lease);
	}
}
