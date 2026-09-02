<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use DateTimeImmutable;
use DateTimeZone;
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
	 * Return every ledger record keyed by its byte-exact migration identifier.
	 *
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
	 * Insert and return a ledger record for a successful migration.
	 *
	 * @throws InvalidMigrationId When the migration identifier is invalid.
	 * @throws LedgerFailure      When the inserted ledger record cannot be read back.
	 */
	public function recordRun(string $migration, int $batch): Record {
		$migration = (new Id($migration))->value;
		$ranAt     = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$this->table->insert([
			'migration' => $migration,
			'batch'     => $batch,
			'ran_at'    => $ranAt->format('Y-m-d H:i:s'),
		]);

		$row = $this->table->query()
			->select('id', 'migration', 'batch', 'ran_at')
			->where('migration', '=', $migration)
			->first();

		if ($row === null) {
			throw LedgerFailure::missingAfterInsert($migration);
		}

		return $this->recordFromRow($row);
	}

	/**
	 * Delete a ledger record and report whether a row was removed.
	 *
	 * @throws InvalidMigrationId When the migration identifier is invalid.
	 */
	public function deleteRun(string $migration): bool {
		$migration = (new Id($migration))->value;

		return $this->table->delete(['migration' => $migration]) > 0;
	}

	/**
	 * Return the next batch number after the latest recorded run.
	 */
	public function nextBatch(): int {
		$latest = $this->latestBatch();

		return $latest === null ? 1 : $latest + 1;
	}

	/**
	 * Return the latest recorded batch, or null when the ledger is empty.
	 */
	public function latestBatch(): ?int {
		$batch = $this->table->query()->max('batch');

		if ($batch === null) {
			return null;
		}

		return (int) $batch;
	}

	/**
	 * Return ledger records belonging to one batch in stored order.
	 *
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
