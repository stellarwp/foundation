<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Exceptions;

use RuntimeException;

/**
 * A migration session failed or lost its original site, connection, or lock.
 */
final class MigrationInterrupted extends RuntimeException
{
}
