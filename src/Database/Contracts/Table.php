<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Defines the identity and schema for a database table.
 */
interface Table
{
	public function id(): string;

	/**
	 * Return the complete physical table name, including the WordPress table prefix.
	 *
	 * For example, an unprefixed table name of `reports` with the WordPress prefix
	 * `wp_` must return `wp_reports`.
	 *
	 * @throws DatabaseException When the resolved physical table name is invalid.
	 */
	public function name(): string;

	public function definition(): TableDefinition;
}
