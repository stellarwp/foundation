<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Inspects physical table, column, and index state.
 */
interface SchemaInspector
{
	/** @throws DatabaseException When table inspection fails. */
	public function tableExists(Table $table): bool;

	/** @throws DatabaseException When column inspection fails. */
	public function columnExists(Table $table, string $column): bool;

	/** @throws DatabaseException When index inspection fails. */
	public function indexExists(Table $table, string $index): bool;
}
