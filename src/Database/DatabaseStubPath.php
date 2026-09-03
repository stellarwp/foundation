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
	 * Return the default generic migration stub path.
	 */
	public static function migration(): string {
		return __DIR__ . '/stubs/migration.stub';
	}

	/**
	 * Return the default create-table migration stub path.
	 */
	public static function createTableMigration(): string {
		return __DIR__ . '/stubs/create-table-migration.stub';
	}

	/**
	 * Return the default table-reconciliation migration stub path.
	 */
	public static function reconcileTableMigration(): string {
		return __DIR__ . '/stubs/reconcile-table-migration.stub';
	}

	/**
	 * Return the default database table stub path.
	 */
	public static function table(): string {
		return __DIR__ . '/stubs/table.stub';
	}
}
