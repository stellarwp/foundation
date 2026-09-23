<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\ValueObjects;

use StellarWP\Foundation\Database\Migration\Contracts\Migration;

/**
 * Associate a persistent ID with a migration's schema and optional data declarations.
 */
final readonly class MigrationRegistration
{
	/**
	 * Register a migration independently of its PHP class name.
	 */
	public function __construct(
		public string $id,
		public Migration $migration,
	) {
	}
}
