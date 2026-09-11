<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock\Exceptions;

use RuntimeException;

/**
 * Indicates that a lock operation could not provide a trustworthy result.
 */
final class LockUnavailableException extends RuntimeException
{
}
