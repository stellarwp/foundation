<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

/**
 * Provides SQL syntax and escaping rules used by the WordPress database implementation.
 */
interface SqlDialect
{
	/** Quote one trusted SQL identifier. */
	public function quoteIdentifier(string $identifier): string;

	/** Escape SQL LIKE wildcard characters without adding a surrounding pattern. */
	public function escLike(string $value): string;
}
