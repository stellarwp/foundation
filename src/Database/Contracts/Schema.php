<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Applies and inspects WordPress database schema state for migrations.
 */
interface Schema
{
	/**
	 * Create or update a table.
	 *
	 * @throws DatabaseException When WordPress cannot reconcile the table definition.
	 */
	public function createOrUpdate(Table $table): void;

	/**
	 * Create or update a table from explicit dbDelta-compatible SQL.
	 *
	 * @throws DatabaseException When WordPress cannot reconcile the SQL definition.
	 */
	public function createOrUpdateSql(string $sql): void;

	/**
	 * Execute explicit schema SQL.
	 *
	 * @throws DatabaseException When the statement cannot be executed.
	 */
	public function execute(string $sql): void;

	/**
	 * @throws DatabaseException When table inspection fails.
	 */
	public function hasTable(Table|string $table): bool;

	/**
	 * @throws DatabaseException When index inspection fails.
	 */
	public function hasIndex(Table|string $table, string $index): bool;

	/**
	 * @throws DatabaseException When the table name is invalid or the statement cannot be executed.
	 */
	public function dropIndex(Table|string $table, string $index): void;

	/**
	 * @throws DatabaseException When the table name is invalid or the statement cannot be executed.
	 */
	public function drop(Table|string $table): void;

	/**
	 * Quote an identifier such as a table, column, or index name.
	 */
	public function quoteIdentifier(string $identifier): string;
}
