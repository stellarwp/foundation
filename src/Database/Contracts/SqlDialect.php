<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

/**
 * Provides SQL syntax and escaping rules used by the WordPress database implementation.
 */
interface SqlDialect
{
	/**
	 * Quote one trusted identifier for use in a generated SQL statement.
	 */
	public function quoteIdentifier(string $identifier): string;

	/**
	 * Escape SQL LIKE wildcards without adding a surrounding match pattern.
	 */
	public function escLike(string $value): string;
}
