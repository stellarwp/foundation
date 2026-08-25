<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Scope;

use StellarWP\Foundation\Database\Contracts\DatabaseScope;
use StellarWP\Foundation\Database\Exceptions\DatabaseContextChanged;

/**
 * Resolves Foundation database resources for the active WordPress site.
 */
final readonly class SiteScope implements DatabaseScope
{
	public function __construct(
		private \wpdb $wpdb
	) {
	}

	public function resolveTableName(string $table): string {
		return $this->wpdb->prefix . $table;
	}

	public function capture(): int {
		return get_current_blog_id();
	}

	/**
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
