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
	 * Return every ledger record keyed by its byte-exact migration identifier.
	 *
	 * @throws InvalidMigrationId When a stored migration identifier is invalid.
	 *
	 * @return array<string, Record>
	 */
	public function all(): array;

	/**
	 * Determine whether a migration identifier has been recorded.
	 *
	 * @throws InvalidMigrationId When the migration identifier is invalid.
	 */
	public function hasRun(string $migration): bool;

	/**
	 * Record a successful migration in the supplied batch.
	 *
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

	/**
	 * Return the batch number to assign to the next successful migration run.
	 */
	public function nextBatch(): int;

	/**
	 * Return the latest recorded batch, or null when the ledger is empty.
	 */
	public function latestBatch(): ?int;

	/**
	 * Return ledger records belonging to one batch in stored order.
	 *
	 * @throws InvalidMigrationId When a stored migration identifier is invalid.
	 *
	 * @return list<Record>
	 */
	public function recordsForBatch(int $batch): array;
}
