<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use DateTimeImmutable;
use DateTimeZone;
use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\Repository as RepositoryContract;
use StellarWP\Foundation\Database\Migration\Exceptions\InvalidMigrationId;
use StellarWP\Foundation\Database\Migration\Exceptions\LedgerFailure;
use StellarWP\Foundation\Database\Migration\ValueObjects\Id;
use StellarWP\Foundation\Database\Migration\ValueObjects\Record;

/**
 * Stores migration records in a WordPress database table.
 */
final readonly class Repository implements RepositoryContract
{
	public function __construct(
		private Database $database,
		private string $table
	) {
	}

	/**
	 * @throws InvalidMigrationId When a stored migration identifier is invalid.
	 *
	 * @return array<string, Record>
	 */
	public function all(): array {
		$records = [];

		foreach ($this->database->rows(sprintf(
			'SELECT id, migration, batch, ran_at FROM %s ORDER BY id ASC',
			$this->database->quoteIdentifier($this->table)
		)) as $row) {
			$record = $this->recordFromRow($row);

			$records[$record->migration] = $record;
		}

		return $records;
	}

	/**
	 * @throws InvalidMigrationId When the migration identifier is invalid.
	 */
	public function hasRun(string $migration): bool {
		$migration = (new Id($migration))->value;

		return $this->database->row(
			'SELECT id FROM %i WHERE migration = %s LIMIT 1',
			$this->table,
			$migration
		) !== null;
	}

	/**
	 * @throws InvalidMigrationId When the migration identifier is invalid.
	 * @throws LedgerFailure      When the inserted ledger record cannot be read back.
	 */
	public function recordRun(string $migration, int $batch): Record {
		$migration = (new Id($migration))->value;
		$ranAt     = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$this->database->execute(
			'INSERT INTO %i (migration, batch, ran_at) VALUES (%s, %d, %s)',
			$this->table,
			$migration,
			$batch,
			$ranAt->format('Y-m-d H:i:s')
		);

		$row = $this->database->row(
			'SELECT id, migration, batch, ran_at FROM %i WHERE migration = %s LIMIT 1',
			$this->table,
			$migration
		);

		if ($row === null) {
			throw LedgerFailure::missingAfterInsert($migration);
		}

		return $this->recordFromRow($row);
	}

	/**
	 * @throws InvalidMigrationId When the migration identifier is invalid.
	 */
	public function deleteRun(string $migration): bool {
		$migration = (new Id($migration))->value;

		return $this->database->execute(
			'DELETE FROM %i WHERE migration = %s',
			$this->table,
			$migration
		) > 0;
	}

	public function nextBatch(): int {
		$latest = $this->latestBatch();

		return $latest === null ? 1 : $latest + 1;
	}

	public function latestBatch(): ?int {
		$row = $this->database->row(sprintf(
			'SELECT MAX(batch) AS batch FROM %s',
			$this->database->quoteIdentifier($this->table)
		));

		if ($row === null || $row['batch'] === null) {
			return null;
		}

		return (int) $row['batch'];
	}

	/**
	 * @throws InvalidMigrationId When a stored migration identifier is invalid.
	 *
	 * @return list<Record>
	 */
	public function recordsForBatch(int $batch): array {
		return array_map(
			fn (array $row): Record => $this->recordFromRow($row),
			$this->database->rows(
				'SELECT id, migration, batch, ran_at FROM %i WHERE batch = %d ORDER BY id ASC',
				$this->table,
				$batch
			)
		);
	}

	/**
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
