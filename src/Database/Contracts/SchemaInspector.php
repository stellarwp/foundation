<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Inspects physical table, column, and index state.
 */
interface SchemaInspector
{
	/**
	 * Determine whether a table exists in the active database scope.
	 *
	 * @throws DatabaseException When table inspection fails.
	 */
	public function tableExists(Table $table): bool;

	/**
	 * Determine whether a table contains the named column.
	 *
	 * @throws DatabaseException When column inspection fails.
	 */
	public function columnExists(Table $table, string $column): bool;

	/**
	 * Determine whether a table contains the named index.
	 *
	 * @throws DatabaseException When index inspection fails.
	 */
	public function indexExists(Table $table, string $index): bool;
}
