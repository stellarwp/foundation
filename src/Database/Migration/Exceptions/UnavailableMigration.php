<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Exceptions;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Reports ledger entries whose migration implementations are unavailable.
 */
final class UnavailableMigration extends DatabaseException
{
	/**
	 * @param non-empty-list<string> $migrations
	 */
	public function __construct(
		public readonly array $migrations
	) {
		parent::__construct(sprintf(
			'Cannot roll back unavailable migrations: %s.',
			implode(', ', $this->migrations)
		));
	}
}
