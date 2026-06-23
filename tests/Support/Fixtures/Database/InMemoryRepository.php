<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use DateTimeImmutable;
use StellarWP\Foundation\Database\Contracts\Repository;
use StellarWP\Foundation\Database\Migration\Record;

final class InMemoryRepository implements Repository
{
	/**
	 * @var array<string, Record>
	 */
	private array $records = [];

	private int $nextId = 1;

	/**
	 * @return array<string, Record>
	 */
	public function all(): array {
		return $this->records;
	}

	public function hasRun(string $migration): bool {
		return isset($this->records[$migration]);
	}

	public function recordRun(string $migration, int $batch): Record {
		$record = new Record(
			id: $this->nextId++,
			migration: $migration,
			batch: $batch,
			ranAt: new DateTimeImmutable('2026-01-01 00:00:00')
		);

		$this->records[$migration] = $record;

		return $record;
	}

	public function deleteRun(string $migration): bool {
		if (! isset($this->records[$migration])) {
			return false;
		}

		unset($this->records[$migration]);

		return true;
	}

	public function nextBatch(): int {
		$latest = $this->latestBatch();

		return $latest === null ? 1 : $latest + 1;
	}

	public function latestBatch(): ?int {
		$batches = array_map(static fn (Record $record): int => $record->batch, $this->records);

		return $batches === [] ? null : max($batches);
	}

	/**
	 * @return list<Record>
	 */
	public function recordsForBatch(int $batch): array {
		return array_values(array_filter(
			$this->records,
			static fn (Record $record): bool => $record->batch === $batch
		));
	}
}
