<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Exceptions;

/**
 * Another database session owns the requested advisory lock.
 */
final class AdvisoryLockContended extends DatabaseException
{
}
