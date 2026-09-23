<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table\Tables;

use Doctrine\DBAL\Connection;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;
use StellarWP\Foundation\Database\Table\Table;

/**
 * Defines the migration ledger table used to record completed migrations.
 *
 * @internal Storage is initialized by the migration history.
 */
final readonly class MigrationTable extends Table
{
	/**
	 * Create the ledger table with its configured unprefixed WordPress name.
	 */
	public function __construct(
		private string $unprefixedTableName,
		Connection $db,
		TableNameResolver $names,
	) {
		parent::__construct($db, $names);
	}

	/**
	 * Return the configured table name before WordPress scope prefixing.
	 */
	public function unprefixedName(): string {
		return $this->unprefixedTableName;
	}
}
