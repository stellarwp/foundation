<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Exceptions;

use RuntimeException;

/**
 * Signals that a caught infrastructure failure prevents transaction completion.
 */
final class TransactionFailed extends RuntimeException
{
}
