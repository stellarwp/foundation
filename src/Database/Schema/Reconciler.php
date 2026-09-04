<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Schema;

use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Schema\Contracts\SchemaExecutor;
use StellarWP\Foundation\Database\Schema\ValueObjects\IndexState;
use StellarWP\Foundation\Database\Table\Blueprint;
use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\Index;

/**
 * Reconciles a physical WordPress database table with its declared definition.
 *
 * @internal Use the public Schema contract for application schema operations.
 *
 * @phpstan-type ColumnProperties array{type: string, nullable: bool, default: mixed, extra: string, comment: string, collation: ?string}
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
	 * Create or reconcile the declarations in a table blueprint through dbDelta.
	 *
	 * @throws DatabaseException When WordPress cannot apply or verify the declared schema.
	 */
	public function reconcile(Blueprint $blueprint): void {
		$table = $blueprint->table();
		$this->executor->execute($this->createTableSql($table, $blueprint));
		$this->applyBinaryDefaults($table, $blueprint);
		$this->assertMatches($blueprint, $this->reconcileColumns($table, $blueprint));
	}

	/**
	 * Verify only the columns and indexes requested by a migration blueprint.
	 *
	 * Physical columns and indexes absent from the blueprint belong to other
	 * migrations or external owners and do not participate in this check.
	 *
	 * @throws DatabaseException When declared schema state is missing, incompatible, or cannot be inspected.
	 */
	public function verify(Blueprint $blueprint): void {
		$this->assertMatches($blueprint, $this->inspectColumns($blueprint->table(), $blueprint->columns()));
	}

	/**
	 * Verify that a table uses the charset and collation configured by WordPress.
	 *
	 * @throws DatabaseException When table metadata is missing, invalid, or incompatible.
	 */
	public function verifyTable(Table $table): void {
		$this->throwForDifferences($table, $this->tableCharsetDifferences($table));
	}

	/**
	 * Determine whether every declaration in a blueprint already matches physical storage.
	 *
	 * @throws DatabaseException When declared schema state cannot be inspected.
	 */
	public function matches(Blueprint $blueprint): bool {
		return $this->declarationDifferences(
			$blueprint->table(),
			$blueprint->columns(),
			$blueprint->indexes(),
			$this->inspectColumns($blueprint->table(), $blueprint->columns())
		) === [];
	}

	/**
	 * Verify one existing column before an alteration treats it as completed work.
	 *
	 * @throws DatabaseException When the column differs from the requested declaration or cannot be inspected.
	 */
	public function verifyColumn(Table $table, Column $column): void {
		$this->throwForDifferences(
			$table,
			$this->columnDifferences([$column], $this->inspectColumns($table, [$column]))
		);
	}

	/**
	 * Verify one existing index before an alteration treats it as completed work.
	 *
	 * @throws DatabaseException When the index differs from the requested declaration or cannot be inspected.
	 */
	public function verifyIndex(Table $table, Index $index): void {
		$this->throwForDifferences($table, $this->indexDifferences($table, [$index]));
	}

	/**
	 * Fail when requested columns or indexes do not match physical storage.
	 *
	 * @param array<string, ColumnProperties> $columnProperties
	 *
	 * @throws DatabaseException When requested schema state is missing or incompatible.
	 */
	private function assertMatches(Blueprint $blueprint, array $columnProperties): void {
		$table = $blueprint->table();

		$this->throwForDifferences(
			$table,
			$this->differences($table, $blueprint->columns(), $blueprint->indexes(), $columnProperties)
		);
	}

	/**
	 * Return differences between requested declarations and physical storage.
	 *
	 * @param list<Column>                    $columns
	 * @param list<Index>                     $indexes
	 * @param array<string, ColumnProperties> $columnProperties
	 *
	 * @throws DatabaseException When requested index state cannot be inspected.
	 *
	 * @return list<string>
	 */
	private function differences(Table $table, array $columns, array $indexes, array $columnProperties): array {
		return [
			...$this->tableCharsetDifferences($table),
			...$this->declarationDifferences($table, $columns, $indexes, $columnProperties),
		];
	}

	/**
	 * Return requested column and index differences without reinspecting table defaults.
	 *
	 * @param list<Column>                    $columns
	 * @param list<Index>                     $indexes
	 * @param array<string, ColumnProperties> $columnProperties
	 *
	 * @throws DatabaseException When requested index state cannot be inspected.
	 *
	 * @return list<string>
	 */
	private function declarationDifferences(Table $table, array $columns, array $indexes, array $columnProperties): array {
		return [
			...$this->columnDifferences($columns, $columnProperties),
			...$this->indexDifferences($table, $indexes),
		];
	}

	/**
	 * Throw a schema exception containing every requested-state difference.
	 *
	 * @param list<string> $differences
	 *
	 * @throws DatabaseException When requested schema state is missing or incompatible.
	 */
	private function throwForDifferences(Table $table, array $differences): void {
		if ($differences === []) {
			return;
		}

		throw new DatabaseException(sprintf(
			'Database schema operation did not apply the requested state for %s: %s.',
			$this->database->tableName($table),
			implode('; ', $differences)
		));
	}

	/**
	 * Build the CREATE TABLE statement passed to the WordPress schema executor.
	 *
	 * @throws DatabaseException When the physical table name is invalid.
	 */
	private function createTableSql(Table $table, Blueprint $blueprint): string {
		$parts = [];

		foreach ($blueprint->columns() as $column) {
			$parts[] = '  ' . $column->sql();
		}

		foreach ($blueprint->indexes() as $index) {
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
	private function applyBinaryDefaults(Table $table, Blueprint $blueprint): void {
		foreach ($blueprint->columns() as $column) {
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
	 * Apply comment changes that dbDelta does not reliably reconcile and retain
	 * the final physical properties for verification.
	 *
	 * MySQL requires the complete column declaration when changing or removing a
	 * comment, so every supported attribute is rendered from the declared column.
	 *
	 * @throws DatabaseException When column metadata is missing, invalid, or cannot be replaced.
	 *
	 * @return array<string, ColumnProperties>
	 */
	private function reconcileColumns(Table $table, Blueprint $blueprint): array {
		$columnProperties = [];

		foreach ($blueprint->columns() as $column) {
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
	 * Inspect every column declared by the blueprint for post-operation verification.
	 *
	 * @param list<Column> $columns
	 *
	 * @throws DatabaseException When column metadata is missing or invalid.
	 *
	 * @return array<string, ColumnProperties>
	 */
	private function inspectColumns(Table $table, array $columns): array {
		$properties = [];

		foreach ($columns as $column) {
			$properties[$column->name] = $this->columnProperties($table, $column);
		}

		return $properties;
	}

	/**
	 * Return every column property that still differs from the declared schema.
	 *
	 * @param list<Column>                    $columns
	 * @param array<string, ColumnProperties> $columnProperties
	 *
	 * @return list<string>
	 */
	private function columnDifferences(array $columns, array $columnProperties): array {
		$differences = [];

		foreach ($columns as $column) {
			$properties = $columnProperties[$column->name];
			$comment    = $column->commentText() ?? '';

			if ($this->normalizeColumnType($properties['type']) !== $this->normalizeColumnType($column->typeSql())) {
				$differences[] = sprintf(
					'column %s expected type %s, found %s',
					$column->name,
					$column->typeSql(),
					$properties['type']
				);
			}

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

			if (! $this->collationMatches($properties['collation'])) {
				$differences[] = sprintf(
					'column %s expected collation %s, found %s',
					$column->name,
					$this->expectedCollationDescription(),
					(string) $properties['collation']
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
	 * @return ColumnProperties
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

		$type      = $row['Type'] ?? null;
		$nullable  = $row['Null'] ?? null;
		$extra     = $row['Extra'] ?? null;
		$comment   = $row['Comment'] ?? '';
		$collation = $row['Collation'] ?? null;

		if (
			! is_string($type)
			|| $type === ''
			|| ! is_string($nullable)
			|| ! in_array(strtoupper($nullable), ['YES', 'NO'], true)
			|| ! array_key_exists('Default', $row)
			|| ! is_string($extra)
			|| ! is_string($comment)
			|| ($collation !== null && (! is_string($collation) || $collation === ''))
		) {
			throw new DatabaseException(sprintf(
				'Database returned invalid column metadata for %s.%s.',
				$this->database->tableName($table),
				$column->name
			));
		}

		return [
			'type'      => $type,
			'nullable'  => strtoupper($nullable) === 'YES',
			'default'   => $row['Default'],
			'extra'     => $extra,
			'comment'   => $comment,
			'collation' => $collation,
		];
	}

	/**
	 * Return a difference when the table default cannot represent WordPress text safely.
	 *
	 * @throws DatabaseException When table metadata is missing or invalid.
	 *
	 * @return list<string>
	 */
	private function tableCharsetDifferences(Table $table): array {
		$expected = $this->expectedCharsetCollation();

		if ($expected === null) {
			return [];
		}

		$tableName = $this->database->tableName($table);
		$row       = $this->database->row('SHOW TABLE STATUS WHERE Name = %s', $tableName);
		$actual    = $row['Collation'] ?? null;

		if (! is_string($actual) || $actual === '') {
			throw new DatabaseException(sprintf('Database returned invalid table metadata for %s.', $tableName));
		}

		if ($this->collationMatches($actual)) {
			return [];
		}

		return [sprintf(
			'table expected collation %s, found %s',
			$this->expectedCollationDescription(),
			$actual
		)];
	}

	/**
	 * Parse the charset and optional explicit collation configured by WordPress.
	 *
	 * @return array{charset: string, collation: ?string}|null
	 */
	private function expectedCharsetCollation(): ?array {
		$clause = $this->database->charsetCollate();

		if (preg_match('/\bCHARACTER\s+SET\s+([A-Za-z0-9_]+)/i', $clause, $charset) !== 1) {
			return null;
		}

		$collation = preg_match('/\bCOLLATE\s+([A-Za-z0-9_]+)/i', $clause, $matches) === 1
			? strtolower($matches[1])
			: null;

		return [
			'charset'   => strtolower($charset[1]),
			'collation' => $collation,
		];
	}

	/**
	 * Determine whether a physical collation satisfies WordPress configuration.
	 */
	private function collationMatches(?string $actual): bool {
		if ($actual === null) {
			return true;
		}

		$expected = $this->expectedCharsetCollation();

		if ($expected === null) {
			return true;
		}

		$actual = strtolower($actual);

		return $expected['collation'] === null
			? str_starts_with($actual, $expected['charset'] . '_')
			: $actual === $expected['collation'];
	}

	/**
	 * Describe the configured collation requirement in reconciliation errors.
	 */
	private function expectedCollationDescription(): string {
		$expected = $this->expectedCharsetCollation();

		if ($expected === null) {
			return 'configured by WordPress';
		}

		return $expected['collation'] ?? $expected['charset'] . '_*';
	}

	/**
	 * Return every missing or incompatible declared index difference.
	 *
	 * @param list<Index> $indexes
	 *
	 * @throws DatabaseException When the database returns invalid index metadata.
	 *
	 * @return list<string>
	 */
	private function indexDifferences(Table $table, array $indexes): array {
		$expected    = $this->expectedIndexes($indexes);
		$differences = [];

		if ($expected === []) {
			return [];
		}

		$actual = $this->physicalIndexes($table, array_keys($expected));

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
		}

		return $differences;
	}

	/**
	 * Normalize the indexes declared by a table definition for comparison with database metadata.
	 *
	 * @param list<Index> $definitions
	 *
	 * @return array<string, IndexState>
	 */
	private function expectedIndexes(array $definitions): array {
		$indexes = [];

		foreach ($definitions as $index) {
			$state = IndexState::fromDefinition($index);

			$indexes[strtolower($state->name)] = $state;
		}

		return $indexes;
	}

	/**
	 * Normalize semantically irrelevant integer display widths before comparison.
	 */
	private function normalizeColumnType(string $type): string {
		$type = trim($type);

		if (preg_match('/\A([a-z]+)(.*)\z/is', $type, $parts) !== 1) {
			return $type;
		}

		$name   = strtolower($parts[1]);
		$suffix = $parts[2];

		if ($name === 'integer') {
			$name = 'int';
		}

		if ($name === 'bool' || $name === 'boolean') {
			$name   = 'tinyint';
			$suffix = '(1)' . $suffix;
		}

		if (in_array($name, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true)) {
			$suffix = preg_replace('/\A\(\d+\)/', '', $suffix) ?? $suffix;

			return trim($name . ' ' . strtolower(trim($suffix)));
		}

		return $name . $suffix;
	}

	/**
	 * Read and normalize the physical indexes reported by MariaDB or MySQL.
	 *
	 * @param list<string> $expectedNames
	 *
	 * @throws DatabaseException When the database returns invalid index metadata.
	 *
	 * @return array<string, IndexState>
	 */
	private function physicalIndexes(Table $table, array $expectedNames): array {
		$tableName = $this->database->tableName($table);
		$expected  = array_fill_keys(array_map(strtolower(...), $expectedNames), true);
		$rows      = array_values(array_filter(
			$this->database->rows('SHOW INDEX FROM %i', $tableName),
			static function (array $row) use ($expected): bool {
				$name = $row['Key_name'] ?? null;

				return is_string($name) && isset($expected[strtolower($name)]);
			}
		));

		return PhysicalIndexCollection::fromRows(
			$rows,
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
