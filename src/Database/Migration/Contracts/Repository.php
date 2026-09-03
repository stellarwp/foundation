<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Contracts;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;
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
	 * @throws DatabaseException  When the migration ledger cannot be read.
	 * @throws InvalidMigrationId When a stored migration identifier is invalid.
	 *
	 * @return array<string, Record>
	 */
	public function all(): array;

	/**
	 * Record a successful migration in the supplied batch.
	 *
	 * @throws DatabaseException  When the migration ledger cannot be written.
	 * @throws InvalidMigrationId When the migration identifier is invalid.
	 * @throws LedgerFailure      When the insert does not record exactly one migration.
	 */
	public function recordRun(string $migration, int $batch): void;

	/**
	 * Return false when no matching ledger row was deleted.
	 *
	 * @throws DatabaseException  When the migration ledger cannot be written.
	 * @throws InvalidMigrationId When the migration identifier is invalid.
	 */
	public function deleteRun(string $migration): bool;

	/**
	 * Return the latest recorded batch, or null when the ledger is empty.
	 *
	 * @throws DatabaseException When the migration ledger cannot be read.
	 */
	public function latestBatch(): ?int;

	/**
	 * Return ledger records belonging to one batch in stored order.
	 *
	 * @throws DatabaseException  When the migration ledger cannot be read.
	 * @throws InvalidMigrationId When a stored migration identifier is invalid.
	 *
	 * @return list<Record>
	 */
	public function recordsForBatch(int $batch): array;
}
