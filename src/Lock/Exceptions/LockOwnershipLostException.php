<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock\Exceptions;

use RuntimeException;

/**
 * Indicates that renewal or release could not confirm continued lock ownership.
 */
final class LockOwnershipLostException extends RuntimeException
{
}
