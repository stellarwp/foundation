<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Migration\Exceptions\InvalidMigrationId;
use StellarWP\Foundation\Database\Migration\Record;

/**
 * Stores and retrieves the migration ledger.
 */
interface Repository
{
	/**
	 * @throws InvalidMigrationId When a stored migration identifier is invalid.
	 *
	 * @return array<string, Record>
	 */
	public function all(): array;

	/**
	 * @throws InvalidMigrationId When the migration identifier is invalid.
	 */
	public function hasRun(string $migration): bool;

	/**
	 * @throws InvalidMigrationId When the migration identifier is invalid.
	 */
	public function recordRun(string $migration, int $batch): Record;

	/**
	 * @throws InvalidMigrationId When the migration identifier is invalid.
	 */
	public function deleteRun(string $migration): bool;

	public function nextBatch(): int;

	public function latestBatch(): ?int;

	/**
	 * @throws InvalidMigrationId When a stored migration identifier is invalid.
	 *
	 * @return list<Record>
	 */
	public function recordsForBatch(int $batch): array;
}
