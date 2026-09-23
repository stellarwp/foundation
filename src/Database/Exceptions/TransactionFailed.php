<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Exceptions;

/**
 * Signals that a caught infrastructure failure prevents transaction completion.
 */
final class TransactionFailed extends DatabaseException
{
}
