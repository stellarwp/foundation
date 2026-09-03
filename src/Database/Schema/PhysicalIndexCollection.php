<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Schema;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Schema\ValueObjects\IndexState;
use StellarWP\Foundation\Database\Table\IndexType;

/**
 * Normalizes the physical indexes reported by MariaDB or MySQL for comparison.
 *
 * @internal Used by schema reconciliation to compare inspected indexes.
 */
final readonly class PhysicalIndexCollection
{
	/**
	 * @param array<string, IndexState> $indexes
	 */
	private function __construct(
		private array $indexes
	) {
	}

	/**
	 * Build a normalized collection from raw SHOW INDEX rows.
	 *
	 * @param list<array<string, mixed>> $rows
	 *
	 * @throws DatabaseException When the database returns invalid index metadata.
	 */
	public static function fromRows(array $rows, string $tableName): self {
		/** @var array<string, array{name: string, type: string, columns: array<int, string>}> $indexes */
		$indexes = [];

		foreach ($rows as $row) {
			$name      = $row['Key_name'] ?? null;
			$column    = $row['Column_name'] ?? null;
			$indexType = $row['Index_type'] ?? null;
			$collation = $row['Collation'] ?? null;
			$nonUnique = filter_var($row['Non_unique'] ?? null, FILTER_VALIDATE_INT);
			$sequence  = filter_var($row['Seq_in_index'] ?? null, FILTER_VALIDATE_INT, [
				'options' => ['min_range' => 1],
			]);

			if (
				! is_string($name)
				|| $name === ''
				|| ! is_string($column)
				|| $column === ''
				|| ! is_string($indexType)
				|| $indexType === ''
				|| ($collation !== null && (! is_string($collation) || ! in_array(strtoupper($collation), ['A', 'D'], true)))
				|| ! in_array($nonUnique, [0, 1], true)
				|| ! is_int($sequence)
			) {
				throw self::invalidMetadata($tableName);
			}

			if (strcasecmp($name, 'PRIMARY') === 0 && $nonUnique !== 0) {
				throw self::invalidMetadata($tableName, $name);
			}

			$key  = strtolower($name);
			$type = self::typeFromMetadata($name, $indexType, $nonUnique);

			if (isset($indexes[$key]) && $indexes[$key]['type'] !== $type) {
				throw self::invalidMetadata($tableName, $name);
			}

			$indexes[$key] ??= [
				'name'    => $name,
				'type'    => $type,
				'columns' => [],
			];

			if (isset($indexes[$key]['columns'][$sequence])) {
				throw self::invalidMetadata($tableName, $name);
			}

			$subPart = $row['Sub_part'] ?? null;

			if ($subPart !== null) {
				$subPart = filter_var($subPart, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

				if (! is_int($subPart)) {
					throw self::invalidMetadata($tableName, $name);
				}

				$column .= '(' . $subPart . ')';
			}

			if (strtoupper((string) $collation) === 'D') {
				$column .= ' DESC';
			}

			$indexes[$key]['columns'][$sequence] = strtolower($column);
		}

		$states = [];

		foreach ($indexes as $key => $index) {
			ksort($index['columns']);

			if (array_keys($index['columns']) !== range(1, count($index['columns']))) {
				throw self::invalidMetadata($tableName, $index['name']);
			}

			$states[$key] = new IndexState(
				$index['name'],
				$index['type'],
				array_values($index['columns'])
			);
		}

		return new self($states);
	}

	/**
	 * Return every physical index keyed by its lowercase database name.
	 *
	 * @return array<string, IndexState>
	 */
	public function all(): array {
		return $this->indexes;
	}

	/**
	 * Normalize storage metadata to the semantic index type used for comparison.
	 */
	private static function typeFromMetadata(string $name, string $indexType, int $nonUnique): string {
		if (strcasecmp($name, 'PRIMARY') === 0) {
			return IndexType::PRIMARY;
		}

		$type = strtolower($indexType);

		if (in_array($type, [IndexState::FULLTEXT, IndexState::SPATIAL, IndexState::RTREE], true)) {
			return $type;
		}

		if ($nonUnique === 0) {
			return IndexType::UNIQUE;
		}

		return IndexType::KEY;
	}

	/**
	 * Build the stable exception used when SHOW INDEX metadata cannot be trusted.
	 */
	private static function invalidMetadata(string $tableName, ?string $indexName = null): DatabaseException {
		return new DatabaseException(sprintf(
			'Database returned invalid index metadata for %s.',
			$indexName === null ? $tableName : $tableName . '.' . $indexName
		));
	}
}
