<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use StellarWP\Foundation\Database\Table\Collection;

/**
 * Manages the database tables required by the migration subsystem itself.
 */
final readonly class Store
{
	public function __construct(
		private Collection $tables
	) {
	}

	/**
	 * Ensure the migration subsystem can record state and coordinate locks.
	 */
	public function prepare(): void {
		$this->tables->create();
	}

	/**
	 * Drop the migration subsystem tables.
	 */
	public function drop(): void {
		$this->tables->drop();
	}

	/**
	 * Determine whether the migration subsystem tables are ready.
	 */
	public function exists(): bool {
		return $this->tables->exists();
	}
}
