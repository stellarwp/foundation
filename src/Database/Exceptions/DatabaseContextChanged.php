<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Exceptions;

/**
 * Indicates that the database context changed during an active operation.
 */
final class DatabaseContextChanged extends DatabaseException
{
	public function __construct(string $expectedContext, string $currentContext) {
		parent::__construct(sprintf(
			'Database operation started in %s but the active context changed to %s. Restore the original context before the operation returns.',
			$expectedContext,
			$currentContext
		));
	}
}
