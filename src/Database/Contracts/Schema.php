<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

/**
 * Applies and inspects WordPress database schema state for migrations.
 */
interface Schema
{
	/**
	 * Create or update a table.
	 */
	public function createOrUpdate(Table|string $table, ?string $sql = null): void;

	/**
	 * Execute explicit schema SQL.
	 */
	public function execute(string $sql): void;

	public function hasTable(Table|string $table): bool;

	public function hasIndex(Table|string $table, string $index): bool;

	public function dropIndex(Table|string $table, string $index): void;

	public function drop(Table|string $table): void;

	/**
	 * Quote an identifier such as a table, column, or index name.
	 */
	public function quoteIdentifier(string $identifier): string;
}
