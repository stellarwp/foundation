<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database;

/**
 * Provides paths to the default database generator stubs shipped with this package.
 */
final class DatabaseStubPath
{
	public static function provider(): string {
		return __DIR__ . '/stubs/provider.stub';
	}

	public static function migration(): string {
		return __DIR__ . '/stubs/migration.stub';
	}

	public static function createTableMigration(): string {
		return __DIR__ . '/stubs/create-table-migration.stub';
	}

	public static function reconcileTableMigration(): string {
		return __DIR__ . '/stubs/reconcile-table-migration.stub';
	}

	public static function table(): string {
		return __DIR__ . '/stubs/table.stub';
	}
}
