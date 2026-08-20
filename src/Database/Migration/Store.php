<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;

/**
 * Manages the database tables required by the migration subsystem itself.
 */
final readonly class Store
{
	public function __construct(
		private Schema $schema,
		private MigrationTable $migrationTable,
		private LockTable $lockTable
	) {
	}

	/**
	 * Ensure the shared lock table is ready before acquiring the migration lock.
	 *
	 * @throws DatabaseException When the lock table cannot be reconciled.
	 */
	public function prepareLock(): void {
		$this->schema->createOrUpdate($this->lockTable);
	}

	/**
	 * Ensure the migration ledger is ready while holding the migration lock.
	 *
	 * @throws DatabaseException When the ledger cannot be reconciled.
	 */
	public function prepareLedger(): void {
		$this->schema->createOrUpdate($this->migrationTable);
	}

	/**
	 * Drop the migration ledger while preserving shared lock storage.
	 *
	 * @throws DatabaseException When the ledger cannot be dropped.
	 */
	public function drop(): void {
		$this->schema->drop($this->migrationTable);
	}

	/**
	 * Determine whether the migration subsystem storage is ready.
	 *
	 * @throws DatabaseException When the ledger cannot be inspected.
	 */
	public function exists(): bool {
		return $this->hasLedger() && $this->schema->hasTable($this->lockTable);
	}

	/**
	 * Determine whether recorded migration state can be read.
	 *
	 * @throws DatabaseException When the ledger cannot be inspected.
	 */
	public function hasLedger(): bool {
		return $this->schema->hasTable($this->migrationTable);
	}
}
