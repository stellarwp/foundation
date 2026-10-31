<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Access the migration's shared connection and scoped historical table names.
 */
final readonly class DataMigrationContext
{
	/**
	 * Receive the active migration's database services.
	 *
	 * @internal Supplied by Migrator to data callbacks.
	 */
	public function __construct(
		public Connection $db,
		public TableNameResolver $names,
	) {
	}

	/**
	 * Resolve and quote a historical unprefixed table name for SQL.
	 *
	 * @throws DatabaseException When the physical name is invalid.
	 * @throws Exception         When the platform cannot be determined.
	 */
	public function quotedTable(string $name): string {
		return $this->db->getDatabasePlatform()->quoteSingleIdentifier($this->names->tableName($name));
	}
}
