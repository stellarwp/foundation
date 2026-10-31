<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

/**
 * Identifies a table in the active WordPress database scope.
 */
interface Table
{
	/**
	 * Return the table name without a WordPress database prefix.
	 *
	 * The database resolves this stable name against the active WordPress scope
	 * when an operation begins. For example, return `reports`, not `wp_reports`.
	 */
	public function unprefixedName(): string;
}
