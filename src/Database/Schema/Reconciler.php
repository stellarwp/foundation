<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Schema;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\SchemaExecutor;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\IndexType;
use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Reconciles a physical WordPress database table with its declared definition.
 */
final readonly class Reconciler
{
	/**
	 * Create a reconciler backed by the database and schema executor.
	 */
	public function __construct(
		private Database $database,
		private SchemaExecutor $executor
	) {
	}

	/**
	 * Create or update the table, then verify properties dbDelta may leave unapplied.
	 *
	 * @throws DatabaseException        When WordPress cannot reconcile the table definition.
	 * @throws InvalidArgumentException When the table definition is invalid.
	 */
	public function reconcile(Table $table): void {
		$definition = $table->definition();
		$definition->assertValid();

		$this->executor->execute($this->createTableSql($table, $definition));
		$this->reconcileComplexDefaults($table, $definition);
		$this->assertColumnPropertiesMatch($table, $definition);
		$this->assertIndexesMatch($table, $definition);
	}

	/**
	 * Build the CREATE TABLE statement passed to the WordPress schema executor.
	 *
	 * @throws DatabaseException When the physical table name is invalid.
	 */
	private function createTableSql(Table $table, TableDefinition $definition): string {
		$parts = [];

		foreach ($definition->columns() as $column) {
			$parts[] = '  ' . $column->sql();
		}

		foreach ($definition->indexes() as $index) {
			$parts[] = '  ' . $index->sql();
		}

		return sprintf(
			"CREATE TABLE %s (\n%s\n) %s;",
			$this->database->quoteIdentifier($this->database->tableName($table)),
			implode(",\n", $parts),
			$this->database->charsetCollate()
		);
	}

	/**
	 * Reconcile binary string defaults that dbDelta cannot represent safely.
	 *
	 * @throws DatabaseException When a default cannot be reconciled.
	 */
	private function reconcileComplexDefaults(Table $table, TableDefinition $definition): void {
		foreach ($definition->columns() as $column) {
			$default = $column->defaultSql();

			if ($default === null || ! str_starts_with($default, "X'")) {
				continue;
			}

			$this->database->execute(sprintf(
				'ALTER TABLE %s ALTER COLUMN %s SET DEFAULT %s',
				$this->database->quoteIdentifier($this->database->tableName($table)),
				$this->database->quoteIdentifier($column->name),
				$default
			));
		}
	}

	/**
	 * Verify properties that dbDelta does not reliably reconcile.
	 *
	 * @throws DatabaseException When column metadata is missing, invalid, or differs from the definition.
	 */
	private function assertColumnPropertiesMatch(Table $table, TableDefinition $definition): void {
		$differences = [];

		foreach ($definition->columns() as $column) {
			$properties = $this->columnProperties($table, $column);

			if ($properties['nullable'] !== $column->nullable) {
				$differences[] = sprintf(
					'column %s expected %s, found %s',
					$column->name,
					$column->nullable ? 'NULL' : 'NOT NULL',
					$properties['nullable'] ? 'NULL' : 'NOT NULL'
				);
			}

			if (! $this->defaultMatches($column, $properties['default'])) {
				$differences[] = sprintf(
					'column %s expected %s, found %s',
					$column->name,
					$column->defaultSql()  === null ? 'no default' : 'DEFAULT ' . $column->defaultSql(),
					$properties['default'] === null ? 'DEFAULT NULL' : 'DEFAULT ' . (string) $properties['default']
				);
			}

			$expectedExtra = $column->autoIncrement ? 'auto_increment' : '';
			$actualExtra   = $this->normalizeExtra($properties['extra']);

			if ($expectedExtra !== $actualExtra) {
				$differences[] = sprintf(
					'column %s expected extra %s, found %s',
					$column->name,
					$expectedExtra === '' ? 'none' : $expectedExtra,
					$actualExtra   === '' ? 'none' : $actualExtra
				);
			}

			$expectedComment = $column->comment ?? '';

			if ($expectedComment !== $properties['comment']) {
				$differences[] = sprintf(
					'column %s expected comment %s, found %s',
					$column->name,
					$expectedComment       === '' ? 'none' : $expectedComment,
					$properties['comment'] === '' ? 'none' : $properties['comment']
				);
			}
		}

		if ($differences !== []) {
			throw new DatabaseException(sprintf(
				'Database schema reconciliation did not apply the definition for %s: %s.',
				$this->database->tableName($table),
				implode('; ', $differences)
			));
		}
	}

	/**
	 * Read and validate the database metadata used to verify a column definition.
	 *
	 * @throws DatabaseException When column metadata is missing or invalid.
	 *
	 * @return array{nullable: bool, default: mixed, extra: string, comment: string}
	 */
	private function columnProperties(Table $table, Column $column): array {
		$row = $this->database->row(
			'SHOW FULL COLUMNS FROM %i WHERE Field = %s',
			$this->database->tableName($table),
			$column->name
		);

		if ($row === null) {
			throw new DatabaseException(sprintf(
				'Database schema reconciliation could not inspect %s.%s.',
				$this->database->tableName($table),
				$column->name
			));
		}

		$nullable = $row['Null'] ?? null;
		$extra    = $row['Extra'] ?? null;
		$comment  = $row['Comment'] ?? '';

		if (
			! is_string($nullable)
			|| ! in_array(strtoupper($nullable), ['YES', 'NO'], true)
			|| ! array_key_exists('Default', $row)
			|| ! is_string($extra)
			|| ! is_string($comment)
		) {
			throw new DatabaseException(sprintf(
				'Database returned invalid column metadata for %s.%s.',
				$this->database->tableName($table),
				$column->name
			));
		}

		return [
			'nullable' => strtoupper($nullable) === 'YES',
			'default'  => $row['Default'],
			'extra'    => $extra,
			'comment'  => $comment,
		];
	}

	/**
	 * Verify that every declared index, and no undeclared index, exists with the expected type and ordered columns.
	 *
	 * @throws DatabaseException When index metadata is invalid or differs from the definition.
	 */
	private function assertIndexesMatch(Table $table, TableDefinition $definition): void {
		$expected    = $this->expectedIndexes($definition);
		$actual      = $this->physicalIndexes($table);
		$differences = [];

		foreach ($expected as $name => $index) {
			if (! isset($actual[$name])) {
				$differences[] = sprintf('index %s expected %s, found missing', $index['name'], $this->describeIndex($index));
				continue;
			}

			if ($index['type'] !== $actual[$name]['type'] || $index['columns'] !== $actual[$name]['columns']) {
				$differences[] = sprintf(
					'index %s expected %s, found %s',
					$index['name'],
					$this->describeIndex($index),
					$this->describeIndex($actual[$name])
				);
			}

			unset($actual[$name]);
		}

		foreach ($actual as $index) {
			$differences[] = sprintf('unexpected index %s found %s', $index['name'], $this->describeIndex($index));
		}

		if ($differences !== []) {
			throw new DatabaseException(sprintf(
				'Database schema reconciliation did not apply the definition for %s: %s.',
				$this->database->tableName($table),
				implode('; ', $differences)
			));
		}
	}

	/**
	 * Normalize the indexes declared by a table definition for comparison with database metadata.
	 *
	 * @return array<string, array{name: string, type: string, columns: list<string>}>
	 */
	private function expectedIndexes(TableDefinition $definition): array {
		$indexes = [];

		foreach ($definition->indexes() as $index) {
			$name = $index->type === IndexType::PRIMARY ? 'PRIMARY' : $index->name;

			$indexes[strtolower($name)] = [
				'name'    => $name,
				'type'    => $index->type,
				'columns' => array_map(strtolower(...), $index->columns),
			];
		}

		return $indexes;
	}

	/**
	 * Read and normalize the physical indexes reported by MariaDB or MySQL.
	 *
	 * @throws DatabaseException When the database returns invalid index metadata.
	 *
	 * @return array<string, array{name: string, type: string, columns: list<string>}>
	 */
	private function physicalIndexes(Table $table): array {
		/** @var array<string, array{name: string, type: string, columns: array<int, string>}> $indexes */
		$indexes = [];

		foreach ($this->database->rows('SHOW INDEX FROM %i', $this->database->tableName($table)) as $row) {
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
				throw new DatabaseException(sprintf(
					'Database returned invalid index metadata for %s.',
					$this->database->tableName($table)
				));
			}

			$key               = strtolower($name);
			$semanticIndexType = strtoupper($indexType);
			$type              = strcasecmp($name, 'PRIMARY') === 0
				? IndexType::PRIMARY
				: (in_array($semanticIndexType, ['FULLTEXT', 'SPATIAL', 'RTREE'], true)
					? strtolower($semanticIndexType)
					: ($nonUnique === 0 ? IndexType::UNIQUE : IndexType::KEY));

			if (isset($indexes[$key]) && $indexes[$key]['type'] !== $type) {
				throw new DatabaseException(sprintf(
					'Database returned invalid index metadata for %s.%s.',
					$this->database->tableName($table),
					$name
				));
			}

			$indexes[$key] ??= [
				'name'    => $name,
				'type'    => $type,
				'columns' => [],
			];

			if (isset($indexes[$key]['columns'][$sequence])) {
				throw new DatabaseException(sprintf(
					'Database returned invalid index metadata for %s.%s.',
					$this->database->tableName($table),
					$name
				));
			}

			$subPart = $row['Sub_part'] ?? null;

			if ($subPart !== null) {
				$subPart = filter_var($subPart, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

				if (! is_int($subPart)) {
					throw new DatabaseException(sprintf(
						'Database returned invalid index metadata for %s.%s.',
						$this->database->tableName($table),
						$name
					));
				}

				$column .= '(' . $subPart . ')';
			}

			if (strtoupper((string) $collation) === 'D') {
				$column .= ' DESC';
			}

			$indexes[$key]['columns'][$sequence] = strtolower($column);
		}

		foreach ($indexes as &$index) {
			ksort($index['columns']);

			if (array_keys($index['columns']) !== range(1, count($index['columns']))) {
				throw new DatabaseException(sprintf(
					'Database returned invalid index metadata for %s.%s.',
					$this->database->tableName($table),
					$index['name']
				));
			}

			$index['columns'] = array_values($index['columns']);
		}

		unset($index);

		return $indexes;
	}

	/**
	 * Format one normalized index for a reconciliation error.
	 *
	 * @param array{name: string, type: string, columns: list<string>} $index
	 */
	private function describeIndex(array $index): string {
		return sprintf('%s (%s)', strtoupper($index['type']), implode(', ', $index['columns']));
	}

	/**
	 * Determine whether a database-reported default matches the declared column default.
	 */
	private function defaultMatches(Column $column, mixed $actual): bool {
		if ($column->default === null) {
			return $actual === null;
		}

		if ($actual === null) {
			return false;
		}

		if (is_bool($column->default)) {
			return $this->integerDefaultMatches($column->default ? 1 : 0, $actual);
		}

		if (is_int($column->default)) {
			return $this->integerDefaultMatches($column->default, $actual);
		}

		if (is_float($column->default)) {
			return is_numeric($actual) && (float) $actual === $column->default;
		}

		return (string) $actual === (string) $column->default;
	}

	/**
	 * Compare an integer default with MySQL integer, decimal, or bit-literal metadata.
	 */
	private function integerDefaultMatches(int $expected, mixed $actual): bool {
		$actual = (string) $actual;

		if (preg_match("/^b'([01]+)'$/i", $actual, $bits) === 1) {
			return bindec($bits[1]) === $expected;
		}

		if (preg_match('/^([+-]?\d+)(?:\.0+)?$/', $actual, $integer) !== 1) {
			return false;
		}

		return filter_var($integer[1], FILTER_VALIDATE_INT) === $expected;
	}

	/**
	 * Normalize equivalent MySQL Extra metadata before comparing column definitions.
	 */
	private function normalizeExtra(string $extra): string {
		$extra = str_ireplace('DEFAULT_GENERATED', '', $extra);
		$extra = preg_replace('/CURRENT_TIMESTAMP\(\)/i', 'CURRENT_TIMESTAMP', $extra) ?? $extra;

		return strtolower(trim(preg_replace('/\s+/', ' ', $extra) ?? $extra));
	}
}
