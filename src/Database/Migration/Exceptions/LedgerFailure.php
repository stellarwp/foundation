<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Exceptions;

use RuntimeException;

/**
 * Schema work completed but recording its result failed; retry through the migrator.
 */
final class LedgerFailure extends RuntimeException
{
}
