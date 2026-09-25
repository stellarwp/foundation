<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\ValueObjects;

use StellarWP\Foundation\Migrations\Contracts\Migration;

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
