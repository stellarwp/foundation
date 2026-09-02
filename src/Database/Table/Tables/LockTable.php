<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table\Tables;

use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\Table;
use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Defines the database-backed lock table used during migration runs.
 */
final readonly class LockTable extends Table
{
	public const string ID = 'foundation_database_locks_table';

	/**
	 * Create the lock table with its configured unprefixed WordPress name.
	 */
	public function __construct(
		string $unprefixedTableName,
		Database $database
	) {
		parent::__construct($unprefixedTableName, $database);
	}

	/**
	 * Return the stable registration identifier for migration lock storage.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Return the schema required for database-backed lock ownership and expiration.
	 */
	public function definition(): TableDefinition {
		$table = TableDefinition::for($this);

		$table->column(new Column('name', 'varbinary', 191));
		$table->column(new Column('owner', 'varbinary', 64));
		$table->dateTime('expires_at', 6);
		$table->dateTime('created_at', 6);
		$table->dateTime('updated_at', 6);
		$table->primary('name');
		$table->index('expires_at', 'expires_at');

		return $table;
	}
}
