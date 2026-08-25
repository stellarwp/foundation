<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table\Tables;

use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Defines the migration ledger table used to record completed migrations.
 */
final readonly class MigrationTable implements Table
{
	public const string ID = 'foundation_database_migrations_table';

	public function __construct(
		private string $table,
		private Database $database
	) {
	}

	public function id(): string {
		return self::ID;
	}

	/**
	 * @throws DatabaseException When the resolved physical table name is invalid.
	 */
	public function name(): string {
		return $this->database->tableName($this->table);
	}

	public function definition(): TableDefinition {
		return TableDefinition::for($this)
			->bigIncrements('id')
			->column(new Column('migration', 'varbinary', 191))
			->unsignedInteger('batch')
			->dateTime('ran_at')
			->unique('migration', 'migration')
			->index('batch', 'batch');
	}
}
