<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Exceptions;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Another database session is upgrading this application's schema.
 */
final class MigrationAlreadyRunning extends DatabaseException
{
}
