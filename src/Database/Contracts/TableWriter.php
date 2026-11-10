<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\QueryException;

/**
 * Performs table-scoped inserts, updates, and deletes.
 */
interface TableWriter
{
	/**
	 * Insert one row into the supplied table.
	 *
	 * @param array<string, mixed> $data
	 *
	 * @throws DatabaseException When table-name resolution fails.
	 * @throws QueryException    When the insert fails.
	 */
	public function insert(Table $table, array $data): int;

	/**
	 * Insert one row and return its generated auto-increment identifier.
	 *
	 * @param array<string, mixed> $data
	 *
	 * @throws DatabaseException When table-name resolution fails.
	 * @throws QueryException    When the insert fails.
	 */
	public function insertGetId(Table $table, array $data): int;

	/**
	 * Update rows matching the supplied equality-based conditions.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 *
	 * @throws DatabaseException When table-name resolution fails.
	 * @throws QueryException    When the update fails.
	 */
	public function update(Table $table, array $data, array $where): int;

	/**
	 * Delete rows matching the supplied equality-based conditions.
	 *
	 * @param array<string, mixed> $where
	 *
	 * @throws DatabaseException When table-name resolution fails.
	 * @throws QueryException    When the delete fails.
	 */
	public function delete(Table $table, array $where): int;
}
