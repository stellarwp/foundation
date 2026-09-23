<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Exceptions;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * A migration session failed or lost its original site, connection, or lock.
 */
final class MigrationInterrupted extends DatabaseException
{
}
