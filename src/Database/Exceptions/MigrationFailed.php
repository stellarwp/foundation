<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Exceptions;

use Throwable;

/**
 * Wraps a failure while applying or rolling back a migration.
 */
final class MigrationFailed extends DatabaseException
{
	public static function whileRunning(string $migration, Throwable $previous): self {
		return new self(sprintf('Migration "%s" failed while running.', $migration), 0, $previous);
	}

	public static function whileRollingBack(string $migration, Throwable $previous): self {
		return new self(sprintf('Migration "%s" failed while rolling back.', $migration), 0, $previous);
	}
}
