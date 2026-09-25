<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Exceptions;

/**
 * Another database session is upgrading this application's schema.
 */
final class MigrationAlreadyRunning extends MigrationException
{
}
