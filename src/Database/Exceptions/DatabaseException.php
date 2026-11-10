<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Exceptions;

use RuntimeException;

/**
 * Base exception for Foundation-owned database failures. Native SQL failures use Doctrine exceptions.
 */
class DatabaseException extends RuntimeException
{
}
