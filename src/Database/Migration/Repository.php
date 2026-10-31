<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use DateTimeImmutable;
use DateTimeZone;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Migration\Contracts\Repository as RepositoryContract;
use StellarWP\Foundation\Database\Migration\Exceptions\InvalidMigrationId;
use StellarWP\Foundation\Database\Migration\Exceptions\LedgerFailure;
use StellarWP\Foundation\Database\Migration\ValueObjects\Id;
use StellarWP\Foundation\Database\Migration\ValueObjects\Record;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;

/**
 * Stores migration records in a WordPress database table.
 */
final readonly class Repository implements RepositoryContract
{
	/**
	 * Create a migration ledger repository backed by its configured table.
	 */
	public function __construct(
		private MigrationTable $table
	) {
	}

	/**
	 * Return every ledger record keyed by its byte-exact migration identifier
	 * in ascending execution order, with the oldest record first.
	 *
	 * @throws DatabaseException  When the migration ledger cannot be read.
	 * @throws InvalidMigrationId When a stored migration identifier is invalid.
	 *
	 * @return array<string, Record>
	 */
	public function all(): array {
		$records = [];

		foreach ($this->table->query()
			->select('id', 'migration', 'batch', 'ran_at')
			->orderBy('id')
			->get() as $row) {
			$record = $this->recordFromRow($row);

			$records[$record->migration] = $record;
		}

		return $records;
	}

	/**
	 * Determine whether a migration identifier has been recorded.
	 *
	 * @throws DatabaseException  When the migration ledger cannot be read.
	 * @throws InvalidMigrationId When the migration identifier is invalid.
	 */
	public function hasRun(string $migration): bool {
		$migration = (new Id($migration))->value;

		return $this->table->query()
			->select('id')
			->where('migration', '=', $migration)
			->first() !== null;
	}

	/**
	 * Insert a ledger record for a successful migration.
	 *
	 * @throws DatabaseException  When the migration ledger cannot be written.
	 * @throws InvalidMigrationId When the migration identifier is invalid.
	 * @throws LedgerFailure      When the insert does not record exactly one migration.
	 */
	public function recordRun(string $migration, int $batch): void {
		$migration = (new Id($migration))->value;
		$ranAt     = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$inserted = $this->table->insert([
			'migration' => $migration,
			'batch'     => $batch,
			'ran_at'    => $ranAt->format('Y-m-d H:i:s'),
		]);

		if ($inserted !== 1) {
			throw LedgerFailure::notInsertedAfterRun($migration);
		}
	}

	/**
	 * Delete a ledger record and report whether a row was removed.
	 *
	 * @throws DatabaseException  When the migration ledger cannot be written.
	 * @throws InvalidMigrationId When the migration identifier is invalid.
	 */
	public function deleteRun(string $migration): bool {
		$migration = (new Id($migration))->value;

		return $this->table->delete(['migration' => $migration]) > 0;
	}

	/**
	 * Return the latest recorded batch, or null when the ledger is empty.
	 *
	 * @throws DatabaseException When the migration ledger cannot be read.
	 */
	public function latestBatch(): ?int {
		$batch = $this->table->query()->max('batch');

		if ($batch === null) {
			return null;
		}

		return (int) $batch;
	}

	/**
	 * Return ledger records belonging to one batch in ascending execution order,
	 * with the oldest record first.
	 *
	 * @throws DatabaseException  When the migration ledger cannot be read.
	 * @throws InvalidMigrationId When a stored migration identifier is invalid.
	 *
	 * @return list<Record>
	 */
	public function recordsForBatch(int $batch): array {
		return array_map(
			fn (array $row): Record => $this->recordFromRow($row),
			$this->table->query()
				->select('id', 'migration', 'batch', 'ran_at')
				->where('batch', '=', $batch)
				->orderBy('id')
				->get()
		);
	}

	/**
	 * Convert one database row into a validated migration ledger record.
	 *
	 * @param array<string, mixed> $row
	 *
	 * @throws InvalidMigrationId When the stored migration identifier is invalid.
	 */
	private function recordFromRow(array $row): Record {
		return new Record(
			id: (int) $row['id'],
			migration: (new Id((string) $row['migration']))->value,
			batch: (int) $row['batch'],
			ranAt: new DateTimeImmutable((string) $row['ran_at'], new DateTimeZone('UTC'))
		);
	}
}
