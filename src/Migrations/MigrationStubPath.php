<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations;

/**
 * Locate the migration declarations supplied to Foundation generators.
 */
final class MigrationStubPath
{
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
	 * Return the default table-alteration migration stub path.
	 */
	public static function alterTableMigration(): string {
		return __DIR__ . '/stubs/alter-table-migration.stub';
	}
}
