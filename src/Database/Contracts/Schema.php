<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Table\Blueprint;

/**
 * Applies and inspects WordPress database schema state for migrations.
 */
interface Schema
{
	/**
	 * Create a missing table or verify an existing table against a historical creation blueprint.
	 *
	 * @throws DatabaseException        When WordPress cannot create the table or its existing state is incompatible.
	 * @throws InvalidArgumentException When the table definition is invalid.
	 */
	public function create(Blueprint $blueprint): void;

	/**
	 * Apply the explicit operations in a blueprint to an existing table.
	 *
	 * @throws DatabaseException        When the table is missing or a schema change cannot be applied or verified.
	 * @throws InvalidArgumentException When the alteration blueprint is invalid.
	 */
	public function alter(Blueprint $blueprint): void;

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
