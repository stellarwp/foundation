<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table\Tables;

use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\Table;
use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Defines the migration ledger table used to record completed migrations.
 */
final readonly class MigrationTable extends Table
{
	public const string ID = 'foundation_database_migrations_table';

	/**
	 * Create the ledger table with its configured unprefixed WordPress name.
	 */
	public function __construct(
		string $unprefixedTableName,
		Database $database
	) {
		parent::__construct($unprefixedTableName, $database);
	}

	/**
	 * Return the stable registration identifier for migration ledger storage.
	 */
	public function id(): string {
		return self::ID;
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
