<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock\Exceptions;

use RuntimeException;

/**
 * Indicates that a lock backend could not provide a trustworthy result.
 */
final class LockUnavailableException extends RuntimeException
{
}
