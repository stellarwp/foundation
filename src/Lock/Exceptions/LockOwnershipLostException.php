<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock\Exceptions;

use RuntimeException;

/**
 * Indicates that ownership was lost before completed work's lock could be released.
 */
final class LockOwnershipLostException extends RuntimeException
{
}
