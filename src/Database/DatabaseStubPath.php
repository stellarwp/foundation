<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database;

/**
 * Provides paths to the default database generator stubs shipped with this package.
 */
final class DatabaseStubPath
{
	/**
	 * Return the default database provider stub path.
	 */
	public static function provider(): string {
		return __DIR__ . '/stubs/provider.stub';
	}

	/**
	 * Return the default database table stub path.
	 */
	public static function table(): string {
		return __DIR__ . '/stubs/table.stub';
	}
}
