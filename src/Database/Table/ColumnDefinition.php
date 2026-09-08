<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Table\ValueObjects\ColumnComment;

/**
 * Applies fluent modifiers to one explicitly selected database column.
 */
final class ColumnDefinition
{
	private string $name;

	private string $type;

	private ?int $length;

	private bool $unsigned;

	private bool $nullable;

	private mixed $default;

	private bool $hasDefault;

	private bool $autoIncrement;

	private ?ColumnComment $comment;

	private bool $changesExistingColumn = false;

	/**
	 * Begin configuring a column from an immutable seed declaration.
	 */
	public function __construct(Column $column) {
		$this->name          = $column->name;
		$this->type          = $column->type;
		$this->length        = $column->length;
		$this->unsigned      = $column->unsigned;
		$this->nullable      = $column->nullable;
		$this->default       = $column->default;
		$this->hasDefault    = $column->hasDefault;
		$this->autoIncrement = $column->autoIncrement;
		$this->comment       = $column->comment;
	}

	/**
	 * Configure whether this column uses an unsigned numeric type.
	 */
	public function unsigned(bool $unsigned = true): self {
		$this->unsigned = $unsigned;

		return $this;
	}

	/**
	 * Configure whether this column accepts NULL values.
	 */
	public function nullable(bool $nullable = true): self {
		$this->nullable = $nullable;

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
		$this->default    = $default;
		$this->hasDefault = true;

		return $this;
	}

	/**
	 * Configure this column to use MySQL's AUTO_INCREMENT attribute.
	 */
	public function autoIncrement(): self {
		$this->autoIncrement = true;

		return $this;
	}

	/**
	 * Configure the descriptive comment stored with this column.
	 *
	 * @throws InvalidArgumentException When the comment requires SQL-mode-dependent escaping.
	 */
	public function comment(string $comment): self {
		$this->comment = new ColumnComment($comment);

		return $this;
	}

	/**
	 * Mark this declaration as a modification of an existing column.
	 *
	 * Without this marker, a declaration in an alteration adds the column when
	 * it is missing and must already match when it exists during a retry.
	 */
	public function change(): self {
		$this->changesExistingColumn = true;

		return $this;
	}

	/**
	 * Determine whether an alteration should modify the existing column.
	 */
	public function changesExistingColumn(): bool {
		return $this->changesExistingColumn;
	}

	/**
	 * Return the immutable column represented by this declaration.
	 */
	public function toColumn(): Column {
		return new Column(
			name: $this->name,
			type: $this->type,
			length: $this->length,
			unsigned: $this->unsigned,
			nullable: $this->nullable,
			default: $this->default,
			hasDefault: $this->hasDefault,
			autoIncrement: $this->autoIncrement,
			comment: $this->comment
		);
	}
}
