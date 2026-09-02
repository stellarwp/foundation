<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Defines the identity and schema for a database table.
 */
interface Table
{
	/**
	 * Return the stable application identifier used to register this table.
	 */
	public function id(): string;

	/**
	 * Return the table name without a WordPress database prefix.
	 *
	 * The database resolves this stable name against the active WordPress scope
	 * when an operation begins. For example, return `reports`, not `wp_reports`.
	 */
	public function unprefixedName(): string;

	/**
	 * Return the table's current schema definition.
	 *
	 * When Foundation replaces a physical column to reconcile its comment, the
	 * complete declared column is authoritative. Attributes not represented in
	 * the definition are not preserved by that replacement.
	 */
	public function definition(): TableDefinition;
}
