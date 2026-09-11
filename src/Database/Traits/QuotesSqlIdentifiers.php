<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Traits;

/**
 * Quotes trusted MySQL identifiers for use in completed SQL statements.
 *
 * @internal
 */
trait QuotesSqlIdentifiers
{
	/**
	 * Wrap an identifier in backticks and escape embedded backticks.
	 */
	private function quoteSqlIdentifier(string $identifier): string {
		return '`' . str_replace('`', '``', $identifier) . '`';
	}
}
