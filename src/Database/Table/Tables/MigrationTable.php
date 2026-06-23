<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table\Tables;

use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Defines the migration ledger table used to record completed migrations.
 */
final readonly class MigrationTable implements Table
{
	public const string ID = 'foundation_database_migrations_table';

	public function __construct(
		private Database $database,
		private string $table
	) {
	}

	public function id(): string {
		return self::ID;
	}

	public function name(): string {
		return $this->database->tableName($this->table);
	}

	public function definition(): TableDefinition {
		return TableDefinition::for($this)
			->bigIncrements('id')
			->string('migration', 191)
			->unsignedInteger('batch')
			->dateTime('ran_at')
			->unique('migration', 'migration')
			->index('batch', 'batch');
	}
}
