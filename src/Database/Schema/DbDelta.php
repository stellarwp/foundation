<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Schema;

use StellarWP\Foundation\Database\Contracts\SchemaExecutor;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\QueryException;

/**
 * Loads and invokes WordPress dbDelta while translating database failures.
 */
final class DbDelta implements SchemaExecutor
{
	/**
	 * @throws DatabaseException When dbDelta or the global WordPress database is unavailable.
	 * @throws QueryException    When dbDelta reports a database error while executing SQL.
	 */
	public function execute(string $sql): void {
		if (! function_exists('dbDelta') && defined('ABSPATH')) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		if (! function_exists('dbDelta')) {
			throw new DatabaseException('WordPress dbDelta() is not available.');
		}

		$wpdb = $GLOBALS['wpdb'] ?? null;

		if (! $wpdb instanceof \wpdb) {
			throw new DatabaseException('The global wpdb instance is not available.');
		}

		dbDelta($sql, true);

		if ($wpdb->last_error !== '') {
			throw new QueryException($wpdb->last_error, $sql, [], $wpdb->last_error);
		}

		$pending = dbDelta($sql, false);

		if ($pending !== []) {
			throw new DatabaseException(sprintf(
				'Database schema reconciliation did not complete: %s',
				implode('; ', $pending)
			));
		}
	}
}
