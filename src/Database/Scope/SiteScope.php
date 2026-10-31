<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Scope;

use StellarWP\Foundation\Database\Contracts\DatabaseScope;
use StellarWP\Foundation\Database\Exceptions\DatabaseContextChanged;

/**
 * Resolves Foundation database resources for the active WordPress site.
 */
final readonly class SiteScope implements DatabaseScope
{
	/**
	 * Create a scope resolver for the active WordPress database connection.
	 */
	public function __construct(
		private \wpdb $wpdb
	) {
	}

	/**
	 * Apply the active WordPress site's table prefix to an unprefixed name.
	 */
	public function resolveTableName(string $unprefixedTableName): string {
		return $this->wpdb->prefix . $unprefixedTableName;
	}

	/**
	 * Capture the active WordPress site identifier for a migration operation.
	 */
	public function capture(): int {
		return get_current_blog_id();
	}

	/**
	 * Confirm that a migration operation remains on its captured WordPress site.
	 *
	 * @throws DatabaseContextChanged When the active WordPress site has changed.
	 */
	public function assertCurrent(int $scopeId): void {
		$currentSiteId = get_current_blog_id();

		if ($currentSiteId !== $scopeId) {
			throw new DatabaseContextChanged(
				'WordPress site ' . $scopeId,
				'WordPress site ' . $currentSiteId
			);
		}
	}
}
