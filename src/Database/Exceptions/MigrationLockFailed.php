<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Exceptions;

/**
 * Raised when a migration lock cannot be acquired or its ownership cannot be confirmed.
 */
final class MigrationLockFailed extends DatabaseException
{
	/**
	 * Create an exception for a migration lock that could not be acquired.
	 */
	public static function forLock(string $lock): self {
		return new self(sprintf('Could not acquire migration lock "%s".', $lock));
	}

	/**
	 * Create an exception when ownership cannot be confirmed during release.
	 */
	public static function forUnconfirmedOwnership(string $lock): self {
		return new self(sprintf('Could not confirm ownership of migration lock "%s" when releasing it.', $lock));
	}
}
