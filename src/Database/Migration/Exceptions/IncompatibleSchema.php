<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Exceptions;

use RuntimeException;

/**
 * The live schema differs from recorded history in a way the pending migration did not declare.
 *
 * The runner never repairs such differences silently; inspect the named table and column,
 * then write a corrective migration or repair the ledger deliberately.
 */
final class IncompatibleSchema extends RuntimeException
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
