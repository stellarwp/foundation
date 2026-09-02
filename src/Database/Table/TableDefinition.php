<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Table;

/**
 * Defines the columns, indexes, and options that make up one database table.
 *
 * Column helpers return a {@see ColumnDefinition} whose modifiers apply only
 * to that column. Indexes and subsequent columns are declared on this object.
 */
final class TableDefinition
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
	 * Create an empty definition owned by one logical table.
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
	 * @throws InvalidArgumentException When no columns are provided.
	 */
	public function primary(string ...$columns): self {
		$this->indexes[] = new Index('primary', $this->nonEmptyColumns(array_values($columns)), IndexType::PRIMARY);

		return $this;
	}

	/**
	 * Add a named unique index over the columns in their declared order.
	 *
	 * @throws InvalidArgumentException When no columns are provided.
	 */
	public function unique(string $name, string ...$columns): self {
		$this->indexes[] = new Index($name, $this->nonEmptyColumns(array_values($columns)), IndexType::UNIQUE);

		return $this;
	}

	/**
	 * Add a named non-unique index over the columns in their declared order.
	 *
	 * @throws InvalidArgumentException When no columns are provided.
	 */
	public function index(string $name, string ...$columns): self {
		$this->indexes[] = new Index($name, $this->nonEmptyColumns(array_values($columns)), IndexType::KEY);

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
	 * Return the configured indexes in declaration order.
	 *
	 * @return list<Index>
	 */
	public function indexes(): array {
		return $this->indexes;
	}

	/**
	 * Return every completed-definition error that would make reconciliation unsafe.
	 *
	 * @return list<string>
	 */
	public function validationErrors(): array {
		$errors = [];

		if ($this->columns === []) {
			$errors[] = sprintf('Table %s does not define any columns.', $this->table->id());
		}

		foreach ($this->columns() as $column) {
			array_push($errors, ...$column->validationErrors());
		}

		foreach ($this->indexes as $index) {
			if ($index->type === IndexType::PRIMARY) {
				continue;
			}

			if (strcasecmp($index->name, 'PRIMARY') === 0) {
				$errors[] = 'The PRIMARY index name is reserved for the primary key.';
				continue;
			}

			foreach ($this->indexesByName($index->name) as $duplicate) {
				if ($duplicate !== $index && $duplicate->type !== IndexType::PRIMARY) {
					$errors[] = sprintf('Index %s is defined more than once.', $index->name);
					break 2;
				}
			}
		}

		if (count(array_filter($this->indexes, static fn (Index $index): bool => $index->type === IndexType::PRIMARY)) > 1) {
			$errors[] = 'A table can define only one primary key.';
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
	 * Assert that this completed definition can be passed to schema reconciliation.
	 *
	 * @throws InvalidArgumentException When columns or indexes form an invalid definition.
	 */
	public function assertValid(): void {
		$errors = $this->validationErrors();

		if ($errors !== []) {
			throw new InvalidArgumentException(implode(' ', $errors));
		}
	}

	/**
	 * Assert that an index declaration contains at least one column.
	 *
	 * @param list<string> $columns
	 *
	 * @throws InvalidArgumentException When the list is empty.
	 *
	 * @return non-empty-list<string>
	 */
	private function nonEmptyColumns(array $columns): array {
		if ($columns === []) {
			throw new InvalidArgumentException('An index must define at least one column.');
		}

		return $columns;
	}

	/**
	 * Return all indexes whose names match case-insensitively.
	 *
	 * @return list<Index>
	 */
	private function indexesByName(string $name): array {
		return array_values(array_filter(
			$this->indexes,
			static fn (Index $index): bool => strcasecmp($index->name, $name) === 0
		));
	}
}
