<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table\Tables;

use StellarWP\Foundation\Database\Contracts\TableGateway;
use StellarWP\Foundation\Database\Table\Table;

/**
 * Defines the database-backed lock table used during migration runs.
 */
final readonly class LockTable extends Table
{
	/**
	 * Create the lock table with its configured unprefixed WordPress name.
	 */
	public function __construct(
		private string $unprefixedTableName,
		TableGateway $database
	) {
		parent::__construct($database);
	}

	/**
	 * Return the configured table name before WordPress scope prefixing.
	 */
	public function unprefixedName(): string {
		return $this->unprefixedTableName;
	}
}
