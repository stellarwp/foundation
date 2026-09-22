<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Exceptions;

use RuntimeException;

/**
 * Another database session is upgrading this application's schema.
 */
final class MigrationAlreadyRunning extends RuntimeException
{
}
