<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Contracts;

use StellarWP\Foundation\Database\Migration\Schema\Blueprint;

/**
 * Declare one historical schema change through Foundation's blueprint vocabulary.
 *
 * Declarations must be pure: no queries, no conditionals on the live database.
 * The runner replays them in memory to compute desired states and decides what
 * remains to be executed, so migrations never need existence guards.
 */
interface Migration
{
	/**
	 * Return the persistent identity that orders this migration, independent of its class name.
	 */
	public function id(): string;

	/**
	 * Declare the forward change.
	 */
	public function up(Blueprint $schema): void;

	/**
	 * Declare the inverse, or throw IrreversibleMigration when reversal would be unsafe.
	 *
	 * @throws \StellarWP\Foundation\Database\Migration\Exceptions\IrreversibleMigration When reversal is unsafe.
	 */
	public function down(Blueprint $schema): void;
}
