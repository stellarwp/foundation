<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

/**
 * Immutable value object describing one database table column.
 *
 * Modifier methods return a new column so a {@see ColumnDefinition} can safely
 * replace its current snapshot without leaking partially configured state.
 */
final readonly class Column
{
	/**
	 * Create a column description from its normalized schema attributes.
	 *
	 * The $hasDefault flag distinguishes an omitted default from an explicit
	 * DEFAULT NULL because both states store null in $default.
	 */
	public function __construct(
		public string $name,
		public string $type,
		public ?int $length = null,
		public bool $unsigned = false,
		public bool $nullable = false,
		public mixed $default = null,
		public bool $hasDefault = false,
		public bool $autoIncrement = false,
		public ?string $comment = null
	) {
	}

	/**
	 * Render this column as the SQL fragment used in a CREATE TABLE statement.
	 */
	public function sql(): string {
		$sql = sprintf(
			'`%s` %s%s%s%s',
			str_replace('`', '``', $this->name),
			$this->type,
			$this->length === null ? '' : sprintf('(%d)', $this->length),
			$this->unsigned ? ' unsigned' : '',
			$this->nullable ? ' NULL' : ' NOT NULL'
		);

		$default = $this->defaultSql();

		if ($default !== null) {
			$sql .= sprintf(' DEFAULT %s', $default);
		}

		if ($this->autoIncrement) {
			$sql .= ' AUTO_INCREMENT';
		}

		if ($this->comment !== null) {
			$sql .= sprintf(" COMMENT '%s'", addslashes($this->comment));
		}

		return $sql;
	}

	/**
	 * Return the SQL literal for an explicit default value.
	 */
	public function defaultSql(): ?string {
		if ($this->default === null && ! $this->hasDefault) {
			return null;
		}

		return $this->formatDefault($this->default);
	}

	/**
	 * Return a copy configured to use or omit the UNSIGNED attribute.
	 */
	public function unsigned(bool $unsigned = true): self {
		return new self(
			name: $this->name,
			type: $this->type,
			length: $this->length,
			unsigned: $unsigned,
			nullable: $this->nullable,
			default: $this->default,
			hasDefault: $this->hasDefault,
			autoIncrement: $this->autoIncrement,
			comment: $this->comment
		);
	}

	/**
	 * Return a copy configured to accept or reject NULL values.
	 */
	public function nullable(bool $nullable = true): self {
		return new self(
			name: $this->name,
			type: $this->type,
			length: $this->length,
			unsigned: $this->unsigned,
			nullable: $nullable,
			default: $this->default,
			hasDefault: $this->hasDefault,
			autoIncrement: $this->autoIncrement,
			comment: $this->comment
		);
	}

	/**
	 * Return a copy with an explicit default value.
	 *
	 * Passing null records DEFAULT NULL but does not make the column nullable;
	 * callers must apply nullable() separately for that final state to be valid.
	 */
	public function default(mixed $default): self {
		return new self(
			name: $this->name,
			type: $this->type,
			length: $this->length,
			unsigned: $this->unsigned,
			nullable: $this->nullable,
			default: $default,
			hasDefault: true,
			autoIncrement: $this->autoIncrement,
			comment: $this->comment
		);
	}

	/**
	 * Return a copy configured to use MySQL's AUTO_INCREMENT attribute.
	 */
	public function autoIncrement(): self {
		return new self(
			name: $this->name,
			type: $this->type,
			length: $this->length,
			unsigned: $this->unsigned,
			nullable: $this->nullable,
			default: $this->default,
			hasDefault: $this->hasDefault,
			autoIncrement: true,
			comment: $this->comment
		);
	}

	/**
	 * Return a copy with the descriptive comment stored in database metadata.
	 */
	public function comment(string $comment): self {
		return new self(
			name: $this->name,
			type: $this->type,
			length: $this->length,
			unsigned: $this->unsigned,
			nullable: $this->nullable,
			default: $this->default,
			hasDefault: $this->hasDefault,
			autoIncrement: $this->autoIncrement,
			comment: $comment
		);
	}

	/**
	 * Return errors for incompatible attributes on this completed column definition.
	 *
	 * @return list<string>
	 */
	public function validationErrors(): array {
		$errors = [];

		if ($this->autoIncrement && $this->nullable) {
			$errors[] = sprintf('Column %s cannot be nullable because it uses AUTO_INCREMENT.', $this->name);
		}

		if ($this->hasDefault && $this->default === null && ! $this->nullable) {
			$errors[] = sprintf('Column %s cannot use DEFAULT NULL unless it is nullable.', $this->name);
		}

		return $errors;
	}

	/**
	 * Format a PHP scalar as a SQL literal suitable for a column default.
	 */
	private function formatDefault(mixed $default): string {
		if ($default === null) {
			return 'NULL';
		}

		if (is_bool($default)) {
			return $default ? '1' : '0';
		}

		if (is_int($default) || is_float($default)) {
			return (string) $default;
		}

		$default = (string) $default;

		if (preg_match("/['\\\\\x00-\x1F\x7F]/", $default) === 1) {
			return "X'" . bin2hex($default) . "'";
		}

		return "'" . $default . "'";
	}
}
