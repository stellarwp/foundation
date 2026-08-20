<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;

/**
 * Manages the database tables required by the migration subsystem itself.
 *
 * @internal Use Migrator as the supported migration lifecycle entry point.
 */
final readonly class Store
{
	public function __construct(
		private MigrationTable $migrationTable,
		private LockTable $lockTable
	) {
	}

	/**
	 * Ensure the shared lock table is ready before acquiring the migration lock.
	 *
	 * @throws DatabaseException When the lock table cannot be reconciled.
	 */
	public function prepareLock(Schema $schema): void {
		$schema->createOrUpdate($this->lockTable);
	}

	/**
	 * Ensure the migration ledger is ready while holding the migration lock.
	 *
	 * @throws DatabaseException When the ledger cannot be reconciled.
	 */
	public function prepareLedger(Schema $schema): void {
		$schema->createOrUpdate($this->migrationTable);
	}

	/**
	 * Drop the migration ledger while preserving shared lock storage.
	 *
	 * @throws DatabaseException When the ledger cannot be dropped.
	 */
	public function drop(Schema $schema): void {
		$schema->drop($this->migrationTable);
	}

	/**
	 * Determine whether the migration subsystem storage is ready.
	 *
	 * @throws DatabaseException When the ledger cannot be inspected.
	 */
	public function exists(Schema $schema): bool {
		return $this->hasLedger($schema) && $schema->hasTable($this->lockTable);
	}

	/**
	 * Determine whether recorded migration state can be read.
	 *
	 * @throws DatabaseException When the ledger cannot be inspected.
	 */
	public function hasLedger(Schema $schema): bool {
		return $schema->hasTable($this->migrationTable);
	}
}
