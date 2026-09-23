<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Contracts;

use Doctrine\DBAL\Connection;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;

/**
 * Optional data step executed after a migration's schema change and before it is recorded.
 *
 * DDL may have committed while the ledger write failed, so this step must be safe to repeat.
 */
interface MigratesData
{
	/**
	 * Transform rows using the shared connection and scoped historical table names.
	 */
	public function migrate(Connection $db, TableNameResolver $names): void;
}
