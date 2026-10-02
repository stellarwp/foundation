<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Exceptions;

/**
 * Reports a migration identifier that cannot be stored safely in the ledger.
 */
final class InvalidMigrationId extends MigrationException
{
}
