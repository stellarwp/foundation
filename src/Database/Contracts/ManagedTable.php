<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Defines a table whose schema lifecycle is managed by Foundation migrations.
 */
interface ManagedTable extends Table
{
	/**
	 * Return the complete schema that Foundation should reconcile for this table.
	 */
	public function definition(): TableDefinition;
}
