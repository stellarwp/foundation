<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Exceptions;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Reports a migration operation attempted before internal storage was initialized.
 */
final class UninitializedStore extends DatabaseException
{
	public function __construct() {
		parent::__construct('Migration storage has not been initialized.');
	}
}
