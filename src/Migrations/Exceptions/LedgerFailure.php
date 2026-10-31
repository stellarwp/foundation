<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Exceptions;

/**
 * Migration work completed but recording failed; inspect and repair before retrying.
 */
final class LedgerFailure extends MigrationException
{
}
