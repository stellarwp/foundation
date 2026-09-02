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
	 * @throws DatabaseException When table inspection fails.
	 */
	public function hasTable(Table $table): bool;

	/**
	 * @throws DatabaseException When index inspection fails.
	 */
	public function hasIndex(Table $table, string $index): bool;

	/**
	 * @throws DatabaseException When the table name is invalid or the statement cannot be executed.
	 */
	public function dropIndex(Table $table, string $index): void;

	/**
	 * @throws DatabaseException When the table name is invalid or the statement cannot be executed.
	 */
	public function drop(Table $table): void;

	/**
	 * Quote an identifier such as a table, column, or index name.
	 */
	public function quoteIdentifier(string $identifier): string;
}
