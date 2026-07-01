<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table\Tables;

use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Defines the database-backed lock table used during migration runs.
 */
final readonly class LockTable implements Table
{
	public const string ID = 'foundation_database_locks_table';

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
			->string('name', 191)
			->string('owner', 64)
			->dateTime('expires_at')
			->dateTime('created_at')
			->dateTime('updated_at')
			->primary('name')
			->index('expires_at', 'expires_at');
	}
}
