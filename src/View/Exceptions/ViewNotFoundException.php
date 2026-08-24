<?php declare(strict_types=1);

namespace StellarWP\Foundation\View\Exceptions;

use RuntimeException;

/**
 * Thrown when a requested view cannot be safely resolved and read.
 */
final class ViewNotFoundException extends RuntimeException
{
}
