<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Migration\Record;

/**
 * Stores and retrieves the migration ledger.
 */
interface Repository
{
	/**
	 * @return array<string, Record>
	 */
	public function all(): array;

	public function hasRun(string $migration): bool;

	public function recordRun(string $migration, int $batch): Record;

	public function deleteRun(string $migration): bool;

	public function nextBatch(): int;

	public function latestBatch(): ?int;

	/**
	 * @return list<Record>
	 */
	public function recordsForBatch(int $batch): array;
}
