<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Schema;

/**
 * Adapts an initial table blueprint into a ledger-recorded migration.
 *
 * @internal Application migrations should construct their historical blueprint inside up().
 */
final readonly class CreateTable implements Migration
{
	/**
	 * Create a migration from its stable identifier and complete initial blueprint.
	 */
	public function __construct(
		private string $migrationId,
		private Blueprint $blueprint
	) {
	}

	/**
	 * Return the stable identifier recorded for this table-creation migration.
	 */
	public function id(): string {
		return $this->migrationId;
	}

	/**
	 * Create the table or verify its existing state from the complete initial blueprint.
	 */
	public function up(Schema $schema): void {
		$schema->create($this->blueprint);
	}

	/**
	 * Drop the complete managed table when this creation migration is reversed.
	 */
	public function down(Schema $schema): void {
		$schema->drop($this->blueprint->table());
	}
}
