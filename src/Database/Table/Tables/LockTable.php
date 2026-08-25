<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table\Tables;

use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Defines the database-backed lock table used during migration runs.
 */
final readonly class LockTable implements Table
{
	public const string ID = 'foundation_database_locks_table';

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
			->column(new Column('name', 'varbinary', 191))
			->column(new Column('owner', 'varbinary', 64))
			->dateTime('expires_at', 6)
			->dateTime('created_at', 6)
			->dateTime('updated_at', 6)
			->primary('name')
			->index('expires_at', 'expires_at');
	}
}
