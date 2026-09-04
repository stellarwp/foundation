<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table\Tables;

use StellarWP\Foundation\Database\Contracts\ManagedTable;
use StellarWP\Foundation\Database\Contracts\TableGateway;
use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\Table;
use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Defines the database-backed lock table used during migration runs.
 */
final readonly class LockTable extends Table implements ManagedTable
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
