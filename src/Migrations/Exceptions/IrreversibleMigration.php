<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Exceptions;

/**
 * Raised by migrations that cannot safely be rolled back.
 */
final class IrreversibleMigration extends MigrationException
{
	public static function forMigration(string $migration): self {
		return new self(sprintf('Migration "%s" cannot be reversed.', $migration));
	}
}
