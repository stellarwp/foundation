<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table\Tables;

use StellarWP\Foundation\Database\Contracts\TableGateway;
use StellarWP\Foundation\Database\Table\Table;

/**
 * Defines the migration ledger table used to record completed migrations.
 */
final readonly class MigrationTable extends Table
{
	/**
	 * Create the ledger table with its configured unprefixed WordPress name.
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
