<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Exceptions;

/**
 * Raised when another process already owns the migration lock.
 */
final class MigrationLockFailed extends DatabaseException
{
	public static function forLock(string $lock): self {
		return new self(sprintf('Could not acquire migration lock "%s".', $lock));
	}
}
