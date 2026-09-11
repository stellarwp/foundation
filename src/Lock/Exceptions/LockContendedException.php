<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock\Exceptions;

use RuntimeException;

/**
 * Indicates that an acquisition attempt observed another owner holding the lock.
 */
final class LockContendedException extends RuntimeException
{
}
