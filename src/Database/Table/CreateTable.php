<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

use StellarWP\Foundation\Database\Contracts\ManagedTable;
use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Schema;

/**
 * Adapts a table definition into a ledger-recorded migration.
 */
final readonly class CreateTable implements Migration
{
	public function __construct(
		private string $migrationId,
		private ManagedTable $table
	) {
	}

	/**
	 * Return the stable identifier recorded for this table-creation migration.
	 */
	public function id(): string {
		return $this->migrationId;
	}

	/**
	 * Create or reconcile the complete managed table definition.
	 */
	public function up(Schema $schema): void {
		$schema->createOrUpdate($this->table);
	}

	/**
	 * Drop the complete managed table when this creation migration is reversed.
	 */
	public function down(Schema $schema): void {
		$schema->drop($this->table);
	}
}
