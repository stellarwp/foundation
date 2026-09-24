<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Exceptions;

/**
 * The live schema differs from recorded history in a way the pending migration did not declare.
 *
 * The runner never repairs such differences silently; inspect the named table and column,
 * then restore the intended schema or correct inaccurate history before retrying.
 */
final class IncompatibleSchema extends MigrationException
{
	/**
	 * Name the migration and the undeclared difference.
	 */
	public static function forChange(string $migrationId, string $table, string $change): self {
		return new self(sprintf(
			'Migration %s cannot proceed: table %s has an undeclared difference (%s). Repair the schema or the ledger before retrying.',
			$migrationId,
			$table,
			$change,
		));
	}
}
