<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Table\ValueObjects\ColumnComment;
use StellarWP\Foundation\Database\Table\ValueObjects\CurrentTimestamp;
use StellarWP\Foundation\Database\Traits\QuotesSqlIdentifiers;

/**
 * Immutable value object describing one completed database table column.
 */
final readonly class Column
{
	use QuotesSqlIdentifiers;

	private const array AUTO_INCREMENT_TYPES = [
		'tinyint',
		'smallint',
		'mediumint',
		'int',
		'integer',
		'bigint',
	];

	/**
	 * Create a column description from its normalized schema attributes.
	 *
	 * The $hasDefault flag distinguishes an omitted default from an explicit
	 * DEFAULT NULL because both states store null in $default.
	 *
	 * @throws InvalidArgumentException When a non-null default is not marked as explicitly configured.
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
		public ?ColumnComment $comment = null,
		public bool $onUpdateCurrentTimestamp = false
	) {
		if (! $this->hasDefault && $this->default !== null) {
			throw new InvalidArgumentException(sprintf(
				'Column %s must set hasDefault when a default value is provided.',
				$this->name
			));
		}
	}

	/**
	 * Render this column as the SQL fragment used in a CREATE TABLE statement.
	 *
	 * @throws InvalidArgumentException When current-timestamp attributes use an invalid type or precision.
	 */
	public function sql(): string {
		$sql = sprintf(
			'%s %s%s',
			$this->quoteSqlIdentifier($this->name),
			$this->typeSql(),
			$this->nullable ? ' NULL' : ' NOT NULL'
		);

		$default = $this->defaultSql();

		if ($default !== null) {
			$sql .= sprintf(' DEFAULT %s', $default);
		}

		$onUpdate = $this->onUpdateSql();

		if ($onUpdate !== null) {
			$sql .= sprintf(' ON UPDATE %s', $onUpdate);
		}

		if ($this->autoIncrement) {
			$sql .= ' AUTO_INCREMENT';
		}

		if ($this->comment !== null) {
			$sql .= ' COMMENT ' . $this->comment->sql();
		}

		return $sql;
	}

	/**
	 * Render the database type, optional length, and unsigned attribute.
	 */
	public function typeSql(): string {
		return sprintf(
			'%s%s',
			$this->canonicalType(),
			$this->unsigned ? ' unsigned' : ''
		);
	}

	/**
	 * Return the SQL literal or current-timestamp expression for an explicit default.
	 *
	 * @throws InvalidArgumentException When a current-timestamp default uses an invalid type or precision.
	 */
	public function defaultSql(): ?string {
		if (! $this->hasDefault) {
			return null;
		}

		if ($this->default instanceof CurrentTimestamp) {
			return $this->currentTimestampSql();
		}

		return $this->formatDefault($this->default);
	}

	/**
	 * Return the automatic-update expression, or null when none is configured.
	 *
	 * @throws InvalidArgumentException When automatic updates use an invalid type or precision.
	 */
	public function onUpdateSql(): ?string {
		return $this->onUpdateCurrentTimestamp ? $this->currentTimestampSql() : null;
	}

	/**
	 * Return the descriptive comment stored in database metadata.
	 */
	public function commentText(): ?string {
		return $this->comment?->comment;
	}

	/**
	 * Return errors for incompatible attributes on this completed column definition.
	 *
	 * @return list<string>
	 */
	public function validationErrors(): array {
		$errors = [];

		if ($this->autoIncrement) {
			preg_match('/\A([a-z]+)/i', trim($this->type), $matches);
			$type = strtolower($matches[1] ?? '');

			if (! in_array($type, self::AUTO_INCREMENT_TYPES, true)) {
				$errors[] = sprintf('Column %s must use an integer type because it uses AUTO_INCREMENT.', $this->name);
			}

			if ($this->nullable) {
				$errors[] = sprintf('Column %s cannot be nullable because it uses AUTO_INCREMENT.', $this->name);
			}

			if ($this->hasDefault) {
				$errors[] = sprintf('Column %s cannot define a default because it uses AUTO_INCREMENT.', $this->name);
			}
		}

		if (! $this->autoIncrement && $this->hasDefault && $this->default === null && ! $this->nullable) {
			$errors[] = sprintf('Column %s cannot use DEFAULT NULL unless it is nullable.', $this->name);
		}

		if (($this->default instanceof CurrentTimestamp || $this->onUpdateCurrentTimestamp) && $this->currentTimestampPrecision() === null) {
			$errors[] = sprintf(
				'Column %s must use DATETIME or TIMESTAMP with precision between 0 and 6 for CURRENT_TIMESTAMP attributes.',
				$this->name
			);
		}

		return $errors;
	}

	/**
	 * Resolve supported temporal precision from the complete SQL type.
	 */
	private function currentTimestampPrecision(): ?int {
		if (preg_match('/\A(?:datetime|timestamp)(?:\s*\(\s*(\d+)\s*\))?\z/i', $this->typeSql(), $parts) !== 1) {
			return null;
		}

		$precision = (int) ($parts[1] ?? 0);

		return $precision <= 6 ? $precision : null;
	}

	/**
	 * Render the current-timestamp expression shared by defaults and updates.
	 *
	 * @throws InvalidArgumentException When the column is not a supported temporal declaration.
	 */
	private function currentTimestampSql(): string {
		$precision = $this->currentTimestampPrecision();

		if ($precision === null) {
			throw new InvalidArgumentException(sprintf(
				'Column %s must use DATETIME or TIMESTAMP with precision between 0 and 6 for CURRENT_TIMESTAMP attributes.',
				$this->name
			));
		}

		return $precision === 0 ? 'CURRENT_TIMESTAMP' : sprintf('CURRENT_TIMESTAMP(%d)', $precision);
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

	/**
	 * Normalize common MySQL type aliases and numeric type arguments before SQL is emitted.
	 *
	 * Literal-bearing types such as ENUM and SET are otherwise preserved byte for
	 * byte so normalization cannot change their declared values.
	 */
	private function canonicalType(): string {
		$type = trim($this->type);

		if ($this->length !== null) {
			$type .= sprintf('(%d)', $this->length);
		}

		if (preg_match('/\Adouble\s+precision\z/i', $type) === 1) {
			return 'double';
		}

		if (preg_match('/\A(?:decimal|numeric|dec)\s*\(\s*(\d+)\s*,\s*(\d+)\s*\)\z/i', $type, $parts) === 1) {
			return sprintf('decimal(%d,%d)', (int) $parts[1], (int) $parts[2]);
		}

		if (preg_match('/\A(?:decimal|numeric|dec)\s*\(\s*(\d+)\s*\)\z/i', $type, $parts) === 1) {
			return sprintf('decimal(%d,0)', (int) $parts[1]);
		}

		if (preg_match('/\A(?:decimal|numeric|dec)\z/i', $type) === 1) {
			return 'decimal(10,0)';
		}

		return $type;
	}
}
