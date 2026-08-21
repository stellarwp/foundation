<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Exceptions;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Reports a migration ledger write that could not be confirmed.
 */
final class LedgerFailure extends DatabaseException
{
	public static function missingAfterInsert(string $migration): self {
		return new self(sprintf('Migration "%s" was inserted but could not be read from the ledger.', $migration));
	}
}
