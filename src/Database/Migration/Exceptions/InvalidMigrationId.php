<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Exceptions;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Reports a migration identifier that cannot be stored safely in the ledger.
 */
final class InvalidMigrationId extends DatabaseException
{
}
