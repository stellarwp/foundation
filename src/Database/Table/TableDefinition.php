<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Table;

/**
 * Defines the columns, indexes, and options that make up one database table.
 */
final class TableDefinition
{
	/**
	 * @var array<string, Column>
	 */
	private array $columns = [];

	/**
	 * @var list<Index>
	 */
	private array $indexes = [];

	private ?string $currentColumn = null;

	private function __construct(
		private readonly Table $table
	) {
	}

	public static function for(Table $table): self {
		return new self($table);
	}

	public function bigIncrements(string $name): self {
		return $this
			->column(new Column($name, 'bigint', 20))
			->unsigned()
			->autoIncrement()
			->primary($name);
	}

	public function string(string $name, int $length = 191, ?string $default = null): self {
		return $this->column(new Column($name, 'varchar', $length, default: $default));
	}

	public function unsignedInteger(string $name, int $length = 10, ?int $default = null): self {
		return $this->column(new Column($name, 'int', $length, unsigned: true, default: $default));
	}

	public function integer(string $name, int $length = 10): self {
		return $this->column(new Column($name, 'int', $length));
	}

	public function tinyInteger(string $name, int $length = 3): self {
		return $this->column(new Column($name, 'tinyint', $length));
	}

	public function bigInteger(string $name, int $length = 20): self {
		return $this->column(new Column($name, 'bigint', $length));
	}

	public function dateTime(string $name): self {
		return $this->column(new Column($name, 'datetime'));
	}

	public function text(string $name): self {
		return $this->column(new Column($name, 'text'));
	}

	public function longText(string $name): self {
		return $this->column(new Column($name, 'longtext'));
	}

	public function column(Column $column): self {
		$this->columns[$column->name] = $column;
		$this->currentColumn          = $column->name;

		return $this;
	}

	public function unsigned(bool $unsigned = true): self {
		return $this->replaceCurrentColumn($this->currentColumn()->unsigned($unsigned));
	}

	public function nullable(bool $nullable = true): self {
		return $this->replaceCurrentColumn($this->currentColumn()->nullable($nullable));
	}

	public function notNull(): self {
		return $this->nullable(false);
	}

	public function default(mixed $default): self {
		return $this->replaceCurrentColumn($this->currentColumn()->default($default));
	}

	public function autoIncrement(): self {
		return $this->replaceCurrentColumn($this->currentColumn()->autoIncrement());
	}

	public function extra(string $extra): self {
		return $this->replaceCurrentColumn($this->currentColumn()->extra($extra));
	}

	private function replaceCurrentColumn(Column $column): self {
		$this->columns[$column->name] = $column;

		return $this;
	}

	public function primary(string ...$columns): self {
		$this->indexes[]     = new Index('primary', $this->nonEmptyColumns(array_values($columns)), IndexType::PRIMARY);
		$this->currentColumn = null;

		return $this;
	}

	public function unique(string $name, string ...$columns): self {
		$this->indexes[]     = new Index($name, $this->nonEmptyColumns(array_values($columns)), IndexType::UNIQUE);
		$this->currentColumn = null;

		return $this;
	}

	public function index(string $name, string ...$columns): self {
		$this->indexes[]     = new Index($name, $this->nonEmptyColumns(array_values($columns)), IndexType::KEY);
		$this->currentColumn = null;

		return $this;
	}

	/**
	 * @return list<Column>
	 */
	public function columns(): array {
		return array_values($this->columns);
	}

	/**
	 * @return list<Index>
	 */
	public function indexes(): array {
		return $this->indexes;
	}

	/**
	 * @return list<string>
	 */
	public function validationErrors(): array {
		$errors = [];

		if ($this->columns === []) {
			$errors[] = sprintf('Table %s does not define any columns.', $this->table->id());
		}

		foreach ($this->indexes as $index) {
			if ($index->type === IndexType::PRIMARY) {
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
				if (! isset($this->columns[$column])) {
					$errors[] = sprintf('Index %s references missing column %s.', $index->name, $column);
				}
			}
		}

		return $errors;
	}

	public function assertValid(): void {
		$errors = $this->validationErrors();

		if ($errors !== []) {
			throw new InvalidArgumentException(implode(' ', $errors));
		}
	}

	/**
	 * @param list<string> $columns
	 *
	 * @return non-empty-list<string>
	 */
	private function nonEmptyColumns(array $columns): array {
		if ($columns === []) {
			throw new InvalidArgumentException('An index must define at least one column.');
		}

		return $columns;
	}

	private function currentColumn(): Column {
		if ($this->currentColumn === null || ! isset($this->columns[$this->currentColumn])) {
			throw new InvalidArgumentException('A column modifier must follow a column definition.');
		}

		return $this->columns[$this->currentColumn];
	}

	/**
	 * @return list<Index>
	 */
	private function indexesByName(string $name): array {
		return array_values(array_filter(
			$this->indexes,
			static fn (Index $index): bool => $index->name === $name
		));
	}
}
