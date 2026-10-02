<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Contracts;

use StellarWP\Foundation\Migrations\Schema\Blueprint;

/**
 * Declare one historical schema change through Foundation's blueprint vocabulary.
 *
 * Declarations must be pure: no queries, no conditionals on the live database.
 * The runner evaluates the current declaration against live tables, or simulated tables
 * during preview. A failed migration requires inspection and repair before retry.
 */
interface Migration
{
	/**
	 * Declare the forward change.
	 */
	public function up(Blueprint $schema): void;

	/**
	 * Declare the inverse, or throw IrreversibleMigration when reversal would be unsafe.
	 *
	 * @throws \StellarWP\Foundation\Migrations\Exceptions\IrreversibleMigration When reversal is unsafe.
	 */
	public function down(Blueprint $schema): void;
}
