<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Exceptions;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Reports a migration ledger write that could not be confirmed.
 */
final class LedgerFailure extends DatabaseException
{
	public static function notInsertedAfterRun(string $migration): self {
		return new self(sprintf('Migration "%s" ran but its ledger record was not inserted.', $migration));
	}

	public static function notDeletedAfterRollback(string $migration): self {
		return new self(sprintf('Migration "%s" was rolled back but its ledger record was not deleted.', $migration));
	}
}
