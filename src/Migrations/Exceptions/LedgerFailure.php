<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Exceptions;

/**
 * Schema work completed but recording its result failed; retry through the migrator.
 */
final class LedgerFailure extends MigrationException
{
}
