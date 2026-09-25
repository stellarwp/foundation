<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Exceptions;

/**
 * Locked database work failed or lost its original site, connection, or ownership.
 */
final class AdvisoryLockInterrupted extends DatabaseException
{
}
