<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Import;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Publish a complete, validated dataset atomically.
 *
 * @internal Example application service.
 */
final readonly class DatasetImporter
{
	/**
	 * Share the same connection with the repository participating in the transaction.
	 */
	public function __construct(
		private Connection $connection,
		private EntryRepository $entries,
	) {
	}

	/**
	 * Replace the dataset and return the committed row count.
	 *
	 * @param list<array{id: int, name: string}> $records Already downloaded and validated records.
	 *
	 * @throws Throwable When the replacement fails or commit is uncertain.
	 */
	public function replace(array $records): int {
		return $this->connection->transactional(function () use ($records): int {
			$this->entries->deleteAll();

			return $this->entries->insertBatch($records);
		});
	}
}
