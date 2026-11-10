<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use StellarWP\Foundation\Database\Contracts\DatabaseScope;

/**
 * Read and write the imported dataset through native Doctrine APIs.
 *
 * @internal Example application repository.
 */
final readonly class EntryRepository
{
	/**
	 * Configure the dataset's stable, unprefixed table name.
	 */
	public function __construct(
		private Connection $db,
		private DatabaseScope $scope,
		private string $tableName,
	) {
	}

	/**
	 * Remove the previous dataset within the application's transaction.
	 *
	 * @throws Exception When the database cannot delete the rows.
	 */
	public function deleteAll(): void {
		$this->db->executeStatement('DELETE FROM ' . $this->table());
	}

	/**
	 * Insert validated records in batches of at most 500 rows.
	 *
	 * @param list<array{id: int, name: string}> $records
	 *
	 * @throws Exception When any batch fails.
	 */
	public function insertBatch(array $records): int {
		$count = 0;
		$table = $this->table();
		foreach (array_chunk($records, 500) as $batch) {
			$values     = implode(', ', array_fill(0, count($batch), '(?, ?)'));
			$parameters = [];
			foreach ($batch as $record) {
				$parameters[] = $record['id'];
				$parameters[] = $record['name'];
			}
			$count += (int) $this->db->executeStatement("INSERT INTO {$table} (id, name) VALUES {$values}", $parameters);
		}

		return $count;
	}

	/**
	 * Find a bounded page of matching entries.
	 *
	 * @throws Exception When the query fails.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function findByName(string $name): array {
		return $this->db->createQueryBuilder()
			->select('id', 'name')
			->from($this->table())
			->where('name = :name')
			->setParameter('name', $name)
			->orderBy('id', 'ASC')
			->setMaxResults(50)
			->executeQuery()
			->fetchAllAssociative();
	}

	private function table(): string {
		return $this->db->getDatabasePlatform()->quoteSingleIdentifier($this->scope->resolveTableName($this->tableName));
	}
}
