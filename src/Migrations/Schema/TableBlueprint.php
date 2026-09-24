<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema;

use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;

/**
 * Fluent table declaration collected during up()/down() and applied to a Doctrine table afterwards.
 *
 * Collecting first and applying later lets `change()` and `dropColumn()` be declared in any order,
 * and keeps Doctrine's schema classes out of migration files.
 */
final class TableBlueprint
{
	/**
	 * @var list<ColumnDefinition>
	 */
	private array $columns = [];
	/**
	 * @var list<array{name: non-empty-string, columns: non-empty-list<non-empty-string>, unique: bool}>
	 */
	private array $indexes = [];
	/**
	 * @var list<non-empty-string>
	 */
	private array $primary = [];
	/**
	 * @var list<non-empty-string>
	 */
	private array $droppedColumns = [];
	/**
	 * @var list<non-empty-string>
	 */
	private array $droppedIndexes = [];
	private ?string $comment      = null;

	/**
	 * Receive the resolved table name.
	 *
	 * @param non-empty-string $name
	 *
	 * @internal Constructed by Foundation; applications receive this object through provider wiring or migration callbacks.
	 */
	public function __construct(
		private readonly string $name,
	) {
	}

	/**
	 * Return the physical table name.
	 *
	 * @return non-empty-string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Unsigned auto-incrementing BIGINT primary key.
	 *
	 * @param non-empty-string $name
	 */
	public function bigIncrements(string $name = 'id'): ColumnDefinition {
		$this->primary = [$name];

		return $this->column($name, Types::BIGINT)->unsigned()->autoIncrement();
	}

	/**
	 * Declare a variable-length string.
	 *
	 * @param non-empty-string $name
	 */
	public function string(string $name, int $length = 191): ColumnDefinition {
		return $this->column($name, Types::STRING, ['length' => $length]);
	}

	/**
	 * TEXT (up to 64 KiB). Use longText() for LONGTEXT.
	 *
	 * @param non-empty-string $name
	 */
	public function text(string $name): ColumnDefinition {
		return $this->column($name, Types::TEXT, ['length' => 65535]);
	}

	/**
	 * Declare a long text column.
	 *
	 * @param non-empty-string $name
	 */
	public function longText(string $name): ColumnDefinition {
		return $this->column($name, Types::TEXT);
	}

	/**
	 * Declare an integer column.
	 *
	 * @param non-empty-string $name
	 */
	public function integer(string $name): ColumnDefinition {
		return $this->column($name, Types::INTEGER);
	}

	/**
	 * Declare an unsigned integer column.
	 *
	 * @param non-empty-string $name
	 */
	public function unsignedInteger(string $name): ColumnDefinition {
		return $this->integer($name)->unsigned();
	}

	/**
	 * Declare a large integer column.
	 *
	 * @param non-empty-string $name
	 */
	public function bigInteger(string $name): ColumnDefinition {
		return $this->column($name, Types::BIGINT);
	}

	/**
	 * Declare an unsigned large integer column.
	 *
	 * @param non-empty-string $name
	 */
	public function unsignedBigInteger(string $name): ColumnDefinition {
		return $this->bigInteger($name)->unsigned();
	}

	/**
	 * Declare boolean storage.
	 *
	 * @param non-empty-string $name
	 */
	public function boolean(string $name): ColumnDefinition {
		return $this->column($name, Types::BOOLEAN);
	}

	/**
	 * Declare an exact decimal precision and scale.
	 *
	 * @param non-empty-string $name
	 */
	public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition {
		return $this->column($name, Types::DECIMAL, ['precision' => $precision, 'scale' => $scale]);
	}

	/**
	 * DATETIME with optional fractional-second precision (0-6).
	 *
	 * @param non-empty-string $name
	 */
	public function dateTime(string $name, ?int $precision = null): ColumnDefinition {
		$definition      = new ColumnDefinition($name, Types::DATETIME_MUTABLE, [], $precision);
		$this->columns[] = $definition;

		return $definition;
	}

	/**
	 * VARBINARY of the given length.
	 *
	 * @param non-empty-string $name
	 */
	public function binary(string $name, int $length): ColumnDefinition {
		return $this->column($name, Types::BINARY, ['length' => $length]);
	}

	/**
	 * Declare the primary key columns.
	 *
	 * @param non-empty-string $columns
	 */
	public function primary(string ...$columns): self {
		$this->primary = array_values($columns);

		return $this;
	}

	/**
	 * Declare a unique index over one or more columns.
	 *
	 * @param non-empty-string $name
	 * @param non-empty-string $columns
	 *
	 * @throws \InvalidArgumentException When no columns are supplied.
	 */
	public function unique(string $name, string ...$columns): self {
		if ($columns === []) {
			throw new \InvalidArgumentException('An index must contain at least one column.');
		}

		$this->indexes[] = ['name' => $name, 'columns' => array_values($columns), 'unique' => true];

		return $this;
	}

	/**
	 * Declare a secondary index over one or more columns.
	 *
	 * @param non-empty-string $name
	 * @param non-empty-string $columns
	 *
	 * @throws \InvalidArgumentException When no columns are supplied.
	 */
	public function index(string $name, string ...$columns): self {
		if ($columns === []) {
			throw new \InvalidArgumentException('An index must contain at least one column.');
		}

		$this->indexes[] = ['name' => $name, 'columns' => array_values($columns), 'unique' => false];

		return $this;
	}

	/**
	 * Remove a column owned by this migration.
	 *
	 * @param non-empty-string $name
	 */
	public function dropColumn(string $name): self {
		$this->droppedColumns[] = $name;

		return $this;
	}

	/**
	 * Remove an index owned by this migration.
	 *
	 * @param non-empty-string $name
	 */
	public function dropIndex(string $name): self {
		$this->droppedIndexes[] = $name;

		return $this;
	}

	/**
	 * Set the table comment.
	 */
	public function comment(string $comment): self {
		$this->comment = $comment;

		return $this;
	}

	/**
	 * Translate the collected declarations onto the Doctrine table.
	 *
	 * @internal Called by Blueprint::apply().
	 */
	public function applyTo(TableEditor $editor, SchemaState $state): Table {
		foreach ($this->droppedIndexes as $index) {
			$editor->dropIndexByUnquotedName($index);
		}
		foreach ($this->droppedColumns as $column) {
			$editor->dropColumnByUnquotedName($column);
			unset($state->timestamps[strtolower($this->name . '.' . $column)]);
		}
		foreach ($this->columns as $column) {
			$column->applyTo($editor);
			$key        = strtolower($this->name . '.' . $column->name());
			$attributes = $column->timestampAttributes();
			unset($state->timestamps[$key]);

			if ($attributes !== null) {
				$state->timestamps[$key] = $attributes;
			}
		}

		if ($this->primary !== []) {
			$editor->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames(...$this->primary)->create());
		}
		foreach ($this->indexes as $index) {
			$editor->addIndex(Index::editor()->setUnquotedName($index['name'])
				->setUnquotedColumnNames(...$index['columns'])
				->setType($index['unique'] ? IndexType::UNIQUE : IndexType::REGULAR)->create());
		}

		if ($this->comment !== null) {
			$editor->setComment($this->comment);
		}

		return $editor->create();
	}

	/**
	 * @param non-empty-string                                  $name
	 * @param array{length?: int, precision?: int, scale?: int} $options
	 */
	private function column(string $name, string $type, array $options = []): ColumnDefinition {
		$definition      = new ColumnDefinition($name, $type, $options);
		$this->columns[] = $definition;

		return $definition;
	}
}
