<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Applies and inspects WordPress database schema state for migrations.
 */
interface Schema
{
	/**
	 * Create or update a table.
	 *
	 * @throws DatabaseException        When WordPress cannot reconcile the table definition.
	 * @throws InvalidArgumentException When the table definition is invalid.
	 */
	public function createOrUpdate(Table $table): void;

	/**
	 * Execute a complete, trusted schema SQL statement without placeholder binding.
	 *
	 * @throws DatabaseException When the statement cannot be executed.
	 */
	public function execute(string $sql): void;

	/**
	 * Determine whether a table exists in the active database scope.
	 *
	 * @throws DatabaseException When table inspection fails.
	 */
	public function hasTable(Table $table): bool;

	/**
	 * Determine whether a named index exists on a table.
	 *
	 * @throws DatabaseException When index inspection fails.
	 */
	public function hasIndex(Table $table, string $index): bool;

	/**
	 * Remove a named secondary index from a table.
	 *
	 * @throws DatabaseException When the table name is invalid or the statement cannot be executed.
	 */
	public function dropIndex(Table $table, string $index): void;

	/**
	 * Drop a table when it exists.
	 *
	 * @throws DatabaseException When the table name is invalid or the statement cannot be executed.
	 */
	public function drop(Table $table): void;

	/**
	 * Quote an identifier such as a table, column, or index name.
	 */
	public function quoteIdentifier(string $identifier): string;
}
