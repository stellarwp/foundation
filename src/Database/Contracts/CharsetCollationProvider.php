<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

/**
 * Provides the charset and collation clause for managed table schemas.
 */
interface CharsetCollationProvider
{
	/**
	 * Return the database charset and collation clause used for managed tables.
	 */
	public function charsetCollate(): string;
}
