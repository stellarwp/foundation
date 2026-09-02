<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

use InvalidArgumentException;

/**
 * Applies fluent modifiers to one explicitly selected database column.
 */
final class ColumnDefinition
{
	public function __construct(
		private Column $column
	) {
	}

	/**
	 * Configure whether this column uses an unsigned numeric type.
	 */
	public function unsigned(bool $unsigned = true): self {
		$this->column = $this->column->unsigned($unsigned);

		return $this;
	}

	/**
	 * Configure whether this column accepts NULL values.
	 */
	public function nullable(bool $nullable = true): self {
		$this->column = $this->column->nullable($nullable);

		return $this;
	}

	/**
	 * Require this column to contain a non-NULL value.
	 */
	public function notNull(): self {
		return $this->nullable(false);
	}

	/**
	 * Configure the default value for this column.
	 */
	public function default(mixed $default): self {
		$this->column = $this->column->default($default);

		return $this;
	}

	/**
	 * Configure this column to use MySQL's AUTO_INCREMENT attribute.
	 */
	public function autoIncrement(): self {
		$this->column = $this->column->autoIncrement();

		return $this;
	}

	/**
	 * Configure the descriptive comment stored with this column.
	 *
	 * @throws InvalidArgumentException When the comment requires SQL-mode-dependent escaping.
	 */
	public function comment(string $comment): self {
		$this->column = $this->column->comment($comment);

		return $this;
	}

	/**
	 * Return the immutable column represented by this declaration.
	 */
	public function toColumn(): Column {
		return $this->column;
	}
}
