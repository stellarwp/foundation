<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Exceptions;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Raised when a rollback does not target the latest recorded migration batch.
 */
final class InvalidRollbackBatch extends DatabaseException
{
	public function __construct(int $requested, ?int $latest) {
		parent::__construct(sprintf(
			'Migration batch %d cannot be rolled back because the latest recorded batch is %s.',
			$requested,
			$latest === null ? 'none' : (string) $latest
		));
	}
}
