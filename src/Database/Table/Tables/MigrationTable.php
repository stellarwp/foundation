<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table\Tables;

use StellarWP\Foundation\Database\Contracts\ManagedTable;
use StellarWP\Foundation\Database\Contracts\TableGateway;
use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\Table;
use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Defines the migration ledger table used to record completed migrations.
 */
final readonly class MigrationTable extends Table implements ManagedTable
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

	/**
	 * Return the schema required to record migration identifiers and batches.
	 */
	public function definition(): TableDefinition {
		$table = TableDefinition::for($this);

		$table->bigIncrements('id');
		$table->column(new Column('migration', 'varbinary', 191));
		$table->unsignedInteger('batch');
		$table->dateTime('ran_at');
		$table->unique('migration', 'migration');
		$table->index('batch', 'batch');

		return $table;
	}
}
