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

	private function __construct(
		private readonly Table $table
	) {
	}

	public static function for(Table $table): self {
		return new self($table);
	}

	public function bigIncrements(string $name): self {
		return $this
			->column(new Column($name, 'bigint', 20, unsigned: true, extra: 'AUTO_INCREMENT'))
			->primary($name);
	}

	public function string(string $name, int $length = 191, ?string $default = null): self {
		return $this->column(new Column($name, 'varchar', $length, default: $default));
	}

	public function unsignedInteger(string $name, int $length = 10, ?int $default = null): self {
		return $this->column(new Column($name, 'int', $length, unsigned: true, default: $default));
	}

	public function dateTime(string $name): self {
		return $this->column(new Column($name, 'datetime'));
	}

	public function text(string $name): self {
		return $this->column(new Column($name, 'text'));
	}

	public function column(Column $column): self {
		$this->columns[$column->name] = $column;

		return $this;
	}

	public function primary(string ...$columns): self {
		$this->indexes[] = new Index('primary', $this->nonEmptyColumns(array_values($columns)), IndexType::PRIMARY);

		return $this;
	}

	public function unique(string $name, string ...$columns): self {
		$this->indexes[] = new Index($name, $this->nonEmptyColumns(array_values($columns)), IndexType::UNIQUE);

		return $this;
	}

	public function index(string $name, string ...$columns): self {
		$this->indexes[] = new Index($name, $this->nonEmptyColumns(array_values($columns)), IndexType::KEY);

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
}
