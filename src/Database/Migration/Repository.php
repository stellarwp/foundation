<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use DateTimeImmutable;
use DateTimeZone;
use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\Repository as RepositoryContract;

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
	 * @return array<string, Record>
	 */
	public function all(): array {
		$records = [];

		foreach ($this->database->rows(sprintf(
			'SELECT id, migration, batch, ran_at FROM %s ORDER BY id ASC',
			$this->database->quoteIdentifier($this->database->tableName($this->table))
		)) as $row) {
			$record = $this->recordFromRow($row);

			$records[$record->migration] = $record;
		}

		return $records;
	}

	public function hasRun(string $migration): bool {
		return $this->database->row(
			'SELECT id FROM %i WHERE migration = %s LIMIT 1',
			$this->database->tableName($this->table),
			$migration
		) !== null;
	}

	public function recordRun(string $migration, int $batch): Record {
		$ranAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$this->database->execute(
			'INSERT INTO %i (migration, batch, ran_at) VALUES (%s, %d, %s)',
			$this->database->tableName($this->table),
			$migration,
			$batch,
			$ranAt->format('Y-m-d H:i:s')
		);

		$row = $this->database->row(
			'SELECT id, migration, batch, ran_at FROM %i WHERE migration = %s LIMIT 1',
			$this->database->tableName($this->table),
			$migration
		);

		if ($row === null) {
			return new Record(0, $migration, $batch, $ranAt);
		}

		return $this->recordFromRow($row);
	}

	public function deleteRun(string $migration): bool {
		return $this->database->execute(
			'DELETE FROM %i WHERE migration = %s',
			$this->database->tableName($this->table),
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
			$this->database->quoteIdentifier($this->database->tableName($this->table))
		));

		if ($row === null || $row['batch'] === null) {
			return null;
		}

		return (int) $row['batch'];
	}

	/**
	 * @return list<Record>
	 */
	public function recordsForBatch(int $batch): array {
		return array_map(
			fn (array $row): Record => $this->recordFromRow($row),
			$this->database->rows(
				'SELECT id, migration, batch, ran_at FROM %i WHERE batch = %d ORDER BY id ASC',
				$this->database->tableName($this->table),
				$batch
			)
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function recordFromRow(array $row): Record {
		return new Record(
			id: (int) $row['id'],
			migration: (string) $row['migration'],
			batch: (int) $row['batch'],
			ranAt: new DateTimeImmutable((string) $row['ran_at'], new DateTimeZone('UTC'))
		);
	}
}
