<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table\Tables;

use Doctrine\DBAL\Connection;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;
use StellarWP\Foundation\Database\Table\Table;

/**
 * Identifies the optional application database-lock table.
 *
 * @internal Storage is initialized by DatabaseLock::initialize().
 */
final readonly class LockTable extends Table
{
	/**
	 * Create the lock table with its configured unprefixed WordPress name.
	 */
	public function __construct(
		private string $unprefixedTableName,
		Connection $connection,
		TableNameResolver $names,
	) {
		parent::__construct($connection, $names);
	}

	/**
	 * Return the configured table name before WordPress scope prefixing.
	 */
	public function unprefixedName(): string {
		return $this->unprefixedTableName;
	}
}
