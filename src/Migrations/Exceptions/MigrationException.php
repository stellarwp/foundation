<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Exceptions;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Base exception for Foundation-owned migration failures.
 */
class MigrationException extends DatabaseException
{
}
