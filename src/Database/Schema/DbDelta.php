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

		dbDelta([$sql], true);

		if ($wpdb->last_error !== '') {
			throw new QueryException($wpdb->last_error, $sql, [], $wpdb->last_error);
		}

		$pending = dbDelta([$sql], false);
		$pending = array_filter(
			$pending,
			fn (string $change): bool => ! $this->createdTableExists($change, $wpdb)
		);

		if ($pending !== []) {
			throw new DatabaseException(sprintf(
				'Database schema reconciliation did not complete: %s',
				implode('; ', $pending)
			));
		}
	}

	/**
	 * Ignore WordPress 6.2's stale dry-run result for a table that was created successfully.
	 *
	 * @throws QueryException When WordPress cannot verify the table.
	 */
	private function createdTableExists(string $change, \wpdb $wpdb): bool {
		$prefix = 'Created table ';

		if (! str_starts_with($change, $prefix)) {
			return false;
		}

		$table = trim(substr($change, strlen($prefix)), '`');
		$query = $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table));
		$found = $wpdb->get_var($query);

		if ($wpdb->last_error !== '') {
			throw new QueryException($wpdb->last_error, 'SHOW TABLES LIKE %s', [$table], $wpdb->last_error);
		}

		return $found === $table;
	}
}
