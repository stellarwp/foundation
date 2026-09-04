<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Schema;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\ManagedTable;
use StellarWP\Foundation\Database\Contracts\SchemaExecutor;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Schema\ValueObjects\IndexState;
use StellarWP\Foundation\Database\Table\Column;
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
	public function reconcile(ManagedTable $table): void {
		$definition = $table->definition();
		$definition->assertValid();

		$this->executor->execute($this->createTableSql($table, $definition));
		$this->applyBinaryDefaults($table, $definition);
		$columnProperties = $this->reconcileCommentsAndInspectColumns($table, $definition);

		$differences = [
			...$this->columnDifferences($definition, $columnProperties),
			...$this->indexDifferences($table, $definition),
		];

		if ($differences !== []) {
			throw new DatabaseException(sprintf(
				'Database schema reconciliation did not apply the definition for %s: %s.',
				$this->database->tableName($table),
				implode('; ', $differences)
			));
		}
	}

	/**
	 * Build the CREATE TABLE statement passed to the WordPress schema executor.
	 *
	 * @throws DatabaseException When the physical table name is invalid.
	 */
	private function createTableSql(ManagedTable $table, TableDefinition $definition): string {
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
	private function applyBinaryDefaults(ManagedTable $table, TableDefinition $definition): void {
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
	 * Apply comment changes that dbDelta does not reliably reconcile, then return
	 * the physical column properties used to verify the complete definition.
	 *
	 * MySQL requires the complete column declaration when changing or removing a
	 * comment, so every supported attribute is rendered from the declared column.
	 *
	 * @throws DatabaseException When column metadata is missing, invalid, or cannot be replaced.
	 *
	 * @return array<string, array{nullable: bool, default: mixed, extra: string, comment: string}>
	 */
	private function reconcileCommentsAndInspectColumns(ManagedTable $table, TableDefinition $definition): array {
		$columnProperties = [];

		foreach ($definition->columns() as $column) {
			$properties = $this->columnProperties($table, $column);
			$comment    = $column->commentText() ?? '';

			if ($comment !== $properties['comment']) {
				$this->database->execute(sprintf(
					'ALTER TABLE %s MODIFY COLUMN %s',
					$this->database->quoteIdentifier($this->database->tableName($table)),
					$column->sql()
				));

				$properties = $this->columnProperties($table, $column);
			}

			$columnProperties[$column->name] = $properties;
		}

		return $columnProperties;
	}

	/**
	 * Return every column property that still differs from the declared schema.
	 *
	 * @param array<string, array{nullable: bool, default: mixed, extra: string, comment: string}> $columnProperties
	 *
	 * @return list<string>
	 */
	private function columnDifferences(TableDefinition $definition, array $columnProperties): array {
		$differences = [];

		foreach ($definition->columns() as $column) {
			$properties = $columnProperties[$column->name];
			$comment    = $column->commentText() ?? '';

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

			if ($comment !== $properties['comment']) {
				$differences[] = sprintf(
					'column %s expected comment %s, found %s',
					$column->name,
					$comment               === '' ? 'none' : $comment,
					$properties['comment'] === '' ? 'none' : $properties['comment']
				);
			}
		}

		return $differences;
	}

	/**
	 * Read and validate the database metadata used to verify a column definition.
	 *
	 * @throws DatabaseException When column metadata is missing or invalid.
	 *
	 * @return array{nullable: bool, default: mixed, extra: string, comment: string}
	 */
	private function columnProperties(ManagedTable $table, Column $column): array {
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
	 * Return every declared, changed, or unexpected physical index difference.
	 *
	 * @throws DatabaseException When the database returns invalid index metadata.
	 *
	 * @return list<string>
	 */
	private function indexDifferences(ManagedTable $table, TableDefinition $definition): array {
		$expected    = $this->expectedIndexes($definition);
		$actual      = $this->physicalIndexes($table);
		$differences = [];

		foreach ($expected as $name => $index) {
			if (! isset($actual[$name])) {
				$differences[] = sprintf('index %s expected %s, found missing', $index->name, $index->describe());
				continue;
			}

			if (! $index->hasSameDefinitionAs($actual[$name])) {
				$differences[] = sprintf(
					'index %s expected %s, found %s',
					$index->name,
					$index->describe(),
					$actual[$name]->describe()
				);
			}

			unset($actual[$name]);
		}

		foreach ($actual as $index) {
			$differences[] = sprintf('unexpected index %s found %s', $index->name, $index->describe());
		}

		return $differences;
	}

	/**
	 * Normalize the indexes declared by a table definition for comparison with database metadata.
	 *
	 * @return array<string, IndexState>
	 */
	private function expectedIndexes(TableDefinition $definition): array {
		$indexes = [];

		foreach ($definition->indexes() as $index) {
			$state = IndexState::fromDefinition($index);

			$indexes[strtolower($state->name)] = $state;
		}

		return $indexes;
	}

	/**
	 * Read and normalize the physical indexes reported by MariaDB or MySQL.
	 *
	 * @throws DatabaseException When the database returns invalid index metadata.
	 *
	 * @return array<string, IndexState>
	 */
	private function physicalIndexes(ManagedTable $table): array {
		$tableName = $this->database->tableName($table);

		return PhysicalIndexCollection::fromRows(
			$this->database->rows('SHOW INDEX FROM %i', $tableName),
			$tableName
		)->all();
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
