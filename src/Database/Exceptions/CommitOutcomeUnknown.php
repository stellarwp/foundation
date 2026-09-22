<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Exceptions;

use RuntimeException;

/**
 * Reports an unacknowledged commit whose durable outcome is uncertain.
 */
final class CommitOutcomeUnknown extends RuntimeException
{
}
