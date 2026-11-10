<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Schema\Reconciler;
use StellarWP\Foundation\Database\Table\Blueprint;
use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;

/**
 * Owns the private tables required by the migration subsystem.
 *
 * @internal Use Migrator as the supported migration lifecycle entry point.
 */
final readonly class StoreSchema
{
	/**
	 * Create the migration storage schema from its public operations, internal reconciler, and tables.
	 */
	public function __construct(
		private Schema $schema,
		private Reconciler $reconciler,
		private MigrationTable $migrationTable,
		private LockTable $lockTable
	) {
	}

	/**
	 * Create or recover the lock table required before a migration lease can be acquired.
	 *
	 * @throws DatabaseException When the lock table cannot be created or reconciled.
	 */
	public function initializeLock(): void {
		$blueprint = Blueprint::for($this->lockTable);

		$blueprint->column(new Column('name', 'varbinary', 191));
		$blueprint->column(new Column('owner', 'varbinary', 64));
		$blueprint->dateTime('expires_at', 6);
		$blueprint->dateTime('created_at', 6);
		$blueprint->dateTime('updated_at', 6);
		$blueprint->primary('name');
		$blueprint->index('expires_at', 'expires_at');

		$this->initialize($blueprint);
	}

	/**
	 * Create or recover the ledger that records completed migration batches.
	 *
	 * @throws DatabaseException When the migration ledger cannot be created or reconciled.
	 */
	public function initializeLedger(): void {
		$blueprint = Blueprint::for($this->migrationTable);

		$blueprint->bigIncrements('id');
		$blueprint->column(new Column('migration', 'varbinary', 191));
		$blueprint->unsignedInteger('batch');
		$blueprint->dateTime('ran_at');
		$blueprint->unique('migration', 'migration');
		$blueprint->index('batch', 'batch');

		$this->initialize($blueprint);
	}

	/**
	 * Determine whether the migration lock table exists in the active database scope.
	 *
	 * @throws DatabaseException When the lock table cannot be inspected.
	 */
	public function hasLock(): bool {
		return $this->schema->hasTable($this->lockTable);
	}

	/**
	 * Determine whether the migration ledger exists in the active database scope.
	 *
	 * @throws DatabaseException When the migration ledger cannot be inspected.
	 */
	public function hasLedger(): bool {
		return $this->schema->hasTable($this->migrationTable);
	}

	/**
	 * Drop the migration ledger while preserving the shared lock table.
	 *
	 * @throws DatabaseException When the migration ledger cannot be dropped.
	 */
	public function dropLedger(): void {
		$this->schema->drop($this->migrationTable);
	}

	/**
	 * Create missing storage through the public schema API or reconcile storage already owned by Foundation.
	 *
	 * @throws DatabaseException When the storage table cannot be created or reconciled.
	 */
	private function initialize(Blueprint $blueprint): void {
		if (! $this->schema->hasTable($blueprint->table())) {
			$this->schema->create($blueprint);

			return;
		}

		$blueprint->assertValidForCreate();
		$this->reconciler->reconcile($blueprint);
	}
}
