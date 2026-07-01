<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Exceptions;

/**
 * Raised when multiple migration objects share the same identifier.
 */
final class DuplicateMigration extends DatabaseException
{
	public static function forMigration(string $migration): self {
		return new self(sprintf('Duplicate migration ID "%s".', $migration));
	}
}
