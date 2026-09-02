<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Contracts;

use StellarWP\Foundation\Database\Migration\Exceptions\InvalidMigrationId;
use StellarWP\Foundation\Database\Migration\Exceptions\LedgerFailure;
use StellarWP\Foundation\Database\Migration\ValueObjects\Record;

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
	 * @throws LedgerFailure      When the inserted ledger record cannot be read back.
	 */
	public function recordRun(string $migration, int $batch): Record;

	/**
	 * Return false when no matching ledger row was deleted.
	 *
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
