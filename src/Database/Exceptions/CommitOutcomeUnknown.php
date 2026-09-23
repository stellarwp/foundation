<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Exceptions;

/**
 * Reports an unacknowledged commit whose durable outcome is uncertain.
 */
final class CommitOutcomeUnknown extends DatabaseException
{
}
