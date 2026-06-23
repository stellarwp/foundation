<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Contracts\Table;

/**
 * Adapts a table definition into a ledger-recorded migration.
 */
final readonly class CreateTable implements Migration
{
	public function __construct(
		private Table $table
	) {
	}

	public function id(): string {
		return $this->table->id();
	}

	public function up(Schema $schema): void {
		if (! $schema->hasTable($this->table)) {
			$schema->createOrUpdate($this->table);
		}
	}

	public function down(Schema $schema): void {
		$schema->drop($this->table);
	}
}
