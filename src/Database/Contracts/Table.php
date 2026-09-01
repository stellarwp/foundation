<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Defines the identity and schema for a database table.
 */
interface Table
{
	public function id(): string;

	/**
	 * Return the table name without a WordPress database prefix.
	 *
	 * The database resolves this stable name against the active WordPress scope
	 * when an operation begins. For example, return `reports`, not `wp_reports`.
	 */
	public function unprefixedName(): string;

	public function definition(): TableDefinition;
}
