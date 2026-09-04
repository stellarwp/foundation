<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Table;

/**
 * Records the table schema operations owned by one migration.
 *
 * Column helpers return a {@see ColumnDefinition} whose modifiers apply only
 * to that column. Indexes and subsequent columns are declared on this object.
 */
final class Blueprint
{
	/**
	 * @var array<string, ColumnDefinition>
	 */
	private array $columns = [];

	/**
	 * @var list<Index>
	 */
	private array $indexes = [];

	/**
	 * @var array<string, string>
	 */
	private array $droppedIndexes = [];

	/**
	 * @var array<string, string>
	 */
	private array $droppedColumns = [];

	/**
	 * Create an empty blueprint for one table.
	 */
	private function __construct(
		private readonly Table $table
	) {
	}

	/**
	 * Begin defining the columns and indexes for a table.
	 */
	public static function for(Table $table): self {
		return new self($table);
	}

	/**
	 * Return the table whose schema this blueprint changes.
	 */
	public function table(): Table {
		return $this->table;
	}

	/**
	 * Add an unsigned BIGINT AUTO_INCREMENT column and make it the primary key.
	 *
	 * @throws InvalidArgumentException When the generated column name is already defined.
	 */
	public function bigIncrements(string $name): ColumnDefinition {
		$column = $this
			->column(new Column($name, 'bigint', 20))
			->unsigned()
			->autoIncrement();

		$this->primary($name);

		return $column;
	}

	/**
	 * Add a VARCHAR column with the requested maximum length.
	 *
	 * @throws InvalidArgumentException When the column name is already defined.
	 */
	public function string(string $name, int $length = 191): ColumnDefinition {
		return $this->column(new Column($name, 'varchar', $length));
	}

	/**
	 * Add an unsigned INT column with the requested display width.
	 *
	 * @throws InvalidArgumentException When the column name is already defined.
	 */
	public function unsignedInteger(string $name, int $length = 10): ColumnDefinition {
		return $this->column(new Column($name, 'int', $length, unsigned: true));
	}

	/**
	 * Add a signed INT column with the requested display width.
	 *
	 * @throws InvalidArgumentException When the column name is already defined.
	 */
	public function integer(string $name, int $length = 10): ColumnDefinition {
		return $this->column(new Column($name, 'int', $length));
	}

	/**
	 * Add a signed TINYINT column with the requested display width.
	 *
	 * @throws InvalidArgumentException When the column name is already defined.
	 */
	public function tinyInteger(string $name, int $length = 3): ColumnDefinition {
		return $this->column(new Column($name, 'tinyint', $length));
	}

	/**
	 * Add a signed BIGINT column with the requested display width.
	 *
	 * @throws InvalidArgumentException When the column name is already defined.
	 */
	public function bigInteger(string $name, int $length = 20): ColumnDefinition {
		return $this->column(new Column($name, 'bigint', $length));
	}

	/**
	 * Add a DATETIME column, optionally with fractional-second precision.
	 *
	 * @throws InvalidArgumentException When the column name is already defined or precision is outside the database-supported range.
	 */
	public function dateTime(string $name, ?int $precision = null): ColumnDefinition {
		if ($precision !== null && ($precision < 0 || $precision > 6)) {
			throw new InvalidArgumentException('Datetime precision must be between 0 and 6.');
		}

		return $this->column(new Column($name, 'datetime', $precision === 0 ? null : $precision));
	}

	/**
	 * Add a TEXT column.
	 *
	 * @throws InvalidArgumentException When the column name is already defined.
	 */
	public function text(string $name): ColumnDefinition {
		return $this->column(new Column($name, 'text'));
	}

	/**
	 * Add a LONGTEXT column.
	 *
	 * @throws InvalidArgumentException When the column name is already defined.
	 */
	public function longText(string $name): ColumnDefinition {
		return $this->column(new Column($name, 'longtext'));
	}

	/**
	 * Add a custom column type and return its fluent declaration.
	 *
	 * Use this method when the named helpers do not represent the required
	 * database type, for example a DECIMAL or VARBINARY column.
	 *
	 * @throws InvalidArgumentException When the column name is already defined.
	 */
	public function column(Column $column): ColumnDefinition {
		$key = strtolower($column->name);

		if (isset($this->columns[$key])) {
			throw new InvalidArgumentException(sprintf('Column %s is already defined.', $column->name));
		}

		$definition          = new ColumnDefinition($column);
		$this->columns[$key] = $definition;

		return $definition;
	}

	/**
	 * Add a primary key over the columns in their declared order.
	 *
	 * @throws InvalidArgumentException When no columns are provided or a column name is blank.
	 */
	public function primary(string ...$columns): self {
		$this->indexes[] = new Index('primary', array_values($columns), IndexType::PRIMARY);

		return $this;
	}

	/**
	 * Add a named unique index over the columns in their declared order.
	 *
	 * @throws InvalidArgumentException When the index name is blank, no columns are provided, or a column name is blank.
	 */
	public function unique(string $name, string ...$columns): self {
		$this->indexes[] = new Index($name, array_values($columns), IndexType::UNIQUE);

		return $this;
	}

	/**
	 * Add a named non-unique index over the columns in their declared order.
	 *
	 * @throws InvalidArgumentException When the index name is blank, no columns are provided, or a column name is blank.
	 */
	public function index(string $name, string ...$columns): self {
		$this->indexes[] = new Index($name, array_values($columns), IndexType::KEY);

		return $this;
	}

	/**
	 * Remove a named secondary index when this blueprint is used to alter a table.
	 *
	 * Repeated declarations are collapsed so retrying a partially completed
	 * migration does not issue the same removal more than once.
	 *
	 * @throws InvalidArgumentException When the name is blank or refers to the primary key.
	 */
	public function dropIndex(string $name): self {
		if (trim($name) === '') {
			throw new InvalidArgumentException('The dropped index name cannot be blank.');
		}

		if (strcasecmp($name, 'PRIMARY') === 0) {
			throw new InvalidArgumentException('Use Schema::execute() with explicit ALTER TABLE SQL to remove the PRIMARY index.');
		}

		$this->droppedIndexes[strtolower($name)] = $name;

		return $this;
	}

	/**
	 * Remove a column when this blueprint is used to alter a table.
	 *
	 * Repeated declarations are collapsed so a migration remains safe to retry
	 * after the database applied its DDL but before the ledger was updated.
	 *
	 * @throws InvalidArgumentException When the column name is blank.
	 */
	public function dropColumn(string $name): self {
		if (trim($name) === '') {
			throw new InvalidArgumentException('The dropped column name cannot be blank.');
		}

		$this->droppedColumns[strtolower($name)] = $name;

		return $this;
	}

	/**
	 * Return immutable snapshots of the configured columns in declaration order.
	 *
	 * @return list<Column>
	 */
	public function columns(): array {
		return array_values(array_map(
			static fn (ColumnDefinition $definition): Column => $definition->toColumn(),
			$this->columns
		));
	}

	/**
	 * Return columns that an alteration should add when they are missing.
	 *
	 * @return list<Column>
	 */
	public function addedColumns(): array {
		return $this->columnsByIntent(false);
	}

	/**
	 * Return columns explicitly marked for modification.
	 *
	 * @return list<Column>
	 */
	public function changedColumns(): array {
		return $this->columnsByIntent(true);
	}

	/**
	 * Return the configured indexes in declaration order.
	 *
	 * @return list<Index>
	 */
	public function indexes(): array {
		return $this->indexes;
	}

	/**
	 * Return named secondary indexes that an alteration should remove first.
	 *
	 * @return list<string>
	 */
	public function droppedIndexes(): array {
		return array_values($this->droppedIndexes);
	}

	/**
	 * Return columns that an alteration should remove.
	 *
	 * @return list<string>
	 */
	public function droppedColumns(): array {
		return array_values($this->droppedColumns);
	}

	/**
	 * Return errors that would make this an invalid initial table definition.
	 *
	 * @return list<string>
	 */
	public function errorsForCreate(): array {
		$errors = $this->commonErrors();

		if ($this->columns === []) {
			array_unshift($errors, sprintf('Table %s does not define any columns.', $this->table->unprefixedName()));
		}

		if ($this->droppedIndexes !== [] || $this->droppedColumns !== []) {
			$errors[] = 'A table creation blueprint cannot remove columns or indexes.';
		}

		foreach ($this->columns as $column) {
			if ($column->changesExistingColumn()) {
				$errors[] = sprintf('Column %s cannot be marked for change when creating a table.', $column->toColumn()->name);
			}
		}

		foreach ($this->columns() as $column) {
			if ($column->autoIncrement && ! $this->isFirstColumnInAnyIndex($column->name)) {
				$errors[] = sprintf('AUTO_INCREMENT column %s must be the first column in an index.', $column->name);
			}
		}

		foreach ($this->indexes as $index) {
			foreach ($index->columns as $column) {
				if (! isset($this->columns[strtolower($column)])) {
					$errors[] = sprintf('Index %s references missing column %s.', $index->name, $column);
				}
			}
		}

		return $errors;
	}

	/**
	 * Return errors that would make this an invalid table alteration.
	 *
	 * Alteration indexes may reference columns already present in the physical
	 * table, so they are not required to appear in this migration's blueprint.
	 *
	 * @return list<string>
	 */
	public function errorsForAlter(): array {
		$errors = $this->commonErrors();

		if ($this->columns === [] && $this->indexes === [] && $this->droppedIndexes === [] && $this->droppedColumns === []) {
			array_unshift($errors, sprintf('Table %s does not define any schema changes.', $this->table->unprefixedName()));
		}

		foreach ($this->droppedColumns as $key => $column) {
			if (isset($this->columns[$key])) {
				$errors[] = sprintf('Column %s cannot be declared and removed by the same blueprint.', $column);
			}
		}

		foreach ($this->addedColumns() as $column) {
			if ($column->autoIncrement && ! $this->isFirstColumnInAnyIndex($column->name)) {
				$errors[] = sprintf('AUTO_INCREMENT column %s must be the first column in an index.', $column->name);
			}
		}

		foreach ($this->indexes as $index) {
			foreach ($index->columns as $column) {
				if (isset($this->droppedColumns[strtolower($column)])) {
					$errors[] = sprintf(
						'Index %s references column %s, which is removed by the same blueprint.',
						$index->name,
						$column
					);
				}
			}
		}

		return $errors;
	}

	/**
	 * Assert that this blueprint can create its table.
	 *
	 * @throws InvalidArgumentException When the initial table definition is invalid.
	 */
	public function assertValidForCreate(): void {
		$this->throwForErrors($this->errorsForCreate());
	}

	/**
	 * Assert that this blueprint can alter an existing table.
	 *
	 * @throws InvalidArgumentException When the requested schema changes are invalid.
	 */
	public function assertValidForAlter(): void {
		$this->throwForErrors($this->errorsForAlter());
	}

	/**
	 * Return errors shared by creation and alteration blueprints.
	 *
	 * @return list<string>
	 */
	private function commonErrors(): array {
		$errors  = [];
		$columns = $this->columns();

		foreach ($columns as $column) {
			array_push($errors, ...$column->validationErrors());
		}

		$autoIncrementColumns = array_values(array_filter(
			$columns,
			static fn (Column $column): bool => $column->autoIncrement
		));

		if (count($autoIncrementColumns) > 1) {
			$errors[] = 'A table can define only one AUTO_INCREMENT column.';
		}

		/** @var array<string, array{name: string, count: int}> $secondaryIndexes */
		$secondaryIndexes = [];
		$primaryCount     = 0;

		foreach ($this->indexes as $index) {
			if ($index->isPrimary()) {
				$primaryCount++;
				continue;
			}

			if (strcasecmp($index->name, 'PRIMARY') === 0) {
				$errors[] = 'The PRIMARY index name is reserved for the primary key.';
				continue;
			}

			$key = strtolower($index->name);
			$secondaryIndexes[$key] ??= ['name' => $index->name, 'count' => 0];
			$secondaryIndexes[$key]['count']++;
		}

		foreach ($secondaryIndexes as $index) {
			if ($index['count'] > 1) {
				$errors[] = sprintf('Index %s is defined more than once.', $index['name']);
			}
		}

		if ($primaryCount > 1) {
			$errors[] = 'A table can define only one primary key.';
		}

		return $errors;
	}

	/**
	 * Return columns matching the requested alteration intent.
	 *
	 * @return list<Column>
	 */
	private function columnsByIntent(bool $changesExistingColumn): array {
		$columns = [];

		foreach ($this->columns as $column) {
			if ($column->changesExistingColumn() === $changesExistingColumn) {
				$columns[] = $column->toColumn();
			}
		}

		return $columns;
	}

	/**
	 * Throw one validation exception containing every blueprint error.
	 *
	 * @param list<string> $errors
	 *
	 * @throws InvalidArgumentException When at least one validation error is present.
	 */
	private function throwForErrors(array $errors): void {
		if ($errors === []) {
			return;
		}

		throw new InvalidArgumentException(implode(' ', $errors));
	}

	/**
	 * Determine whether a column leads at least one declared table index.
	 */
	private function isFirstColumnInAnyIndex(string $column): bool {
		foreach ($this->indexes as $index) {
			if (strcasecmp($index->columns[0], $column) === 0) {
				return true;
			}
		}

		return false;
	}
}
