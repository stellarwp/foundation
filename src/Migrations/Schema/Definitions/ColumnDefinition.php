<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema\Definitions;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;

/**
 * Fluent column declaration that is translated to a Doctrine column when the blueprint is applied.
 *
 * Only this class knows Doctrine's column option names, so a change in DBAL's schema API is
 * absorbed here rather than in application migrations.
 */
final class ColumnDefinition
{
	private bool $nullable        = false;
	private bool $unsigned        = false;
	private bool $autoIncrement   = false;
	private bool $currentDefault  = false;
	private bool $currentOnUpdate = false;
	private bool $change          = false;
	private bool $hasDefault      = false;
	private mixed $default        = null;
	private ?string $comment      = null;

	/**
	 * Collect a complete column declaration.
	 *
	 * @param non-empty-string                                                $name    Column identifier.
	 * @param array{length?: int, fixed?: bool, precision?: int, scale?: int} $options Type-specific Doctrine options such as length, precision, scale.
	 *
	 * @internal Constructed by Foundation; applications receive this object through provider wiring or migration callbacks.
	 */
	public function __construct(
		private readonly string $name,
		private readonly string $type,
		private readonly array $options = [],
		private readonly ?int $precision = null,
	) {
	}

	/**
	 * Allow null values in this column.
	 */
	public function nullable(bool $nullable = true): self {
		$this->nullable = $nullable;

		return $this;
	}

	/**
	 * Require a non-null value.
	 */
	public function notNull(): self {
		return $this->nullable(false);
	}

	/**
	 * Use an unsigned numeric column.
	 */
	public function unsigned(bool $unsigned = true): self {
		$this->unsigned = $unsigned;

		return $this;
	}

	/**
	 * Declare the database default value.
	 */
	public function default(mixed $default): self {
		$this->hasDefault = true;
		$this->default    = $default;

		return $this;
	}

	/**
	 * Let the database fill the column with the current timestamp on insert.
	 */
	public function useCurrent(): self {
		$this->currentDefault = true;

		return $this;
	}

	/**
	 * Let the database refresh the column with the current timestamp on every update.
	 */
	public function useCurrentOnUpdate(): self {
		$this->currentOnUpdate = true;

		return $this;
	}

	/**
	 * Generate increasing numeric identifiers.
	 */
	public function autoIncrement(): self {
		$this->autoIncrement = true;

		return $this;
	}

	/**
	 * Set the column comment.
	 */
	public function comment(string $comment): self {
		$this->comment = $comment;

		return $this;
	}

	/**
	 * Replace an existing column's complete definition instead of adding a new column.
	 */
	public function change(): self {
		$this->change = true;

		return $this;
	}

	/**
	 * Return the declared column name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Add or modify the Doctrine column on the given table.
	 *
	 * @internal Called by TableBlueprint while the runner applies declarations.
	 */
	public function applyTo(TableEditor $table): void {
		$column = Column::editor()
			->setUnquotedName($this->name)
			->setTypeName($this->type)
			->setNotNull(! $this->nullable)
			->setUnsigned($this->unsigned)
			->setFixed($this->options['fixed'] ?? false)
			->setAutoincrement($this->autoIncrement)
			->setComment($this->comment ?? '');

		if (isset($this->options['length'])) {
			$column->setLength($this->options['length']);
		}

		if (isset($this->options['precision'])) {
			$column->setPrecision($this->options['precision']);
		}

		if (isset($this->options['scale'])) {
			$column->setScale($this->options['scale']);
		}

		if ($this->hasDefault) {
			$column->setDefaultValue($this->normalizedDefault());
		}

		if ($this->currentDefault) {
			$column->setDefaultValue($this->currentTimestamp());
		}

		if ($this->isTimestamp()) {
			$column->setColumnDefinition($this->timestampDefinition());
		}

		if ($this->change) {
			$table->dropColumnByUnquotedName($this->name);
		}
		$table->addColumn($column->create());
	}

	/**
	 * Return the timestamp facts carried beside Doctrine's schema representation.
	 *
	 * @internal
	 *
	 * @return array{precision: int, on_update: bool}|null
	 */
	public function timestampAttributes(): ?array {
		return $this->isTimestamp() ? ['precision' => $this->precision ?? 0, 'on_update' => $this->currentOnUpdate] : null;
	}

	/**
	 * MySQL reports decimal defaults at the column's scale ('0.0000'); declare them the same way so
	 * the comparator sees no difference on a second run.
	 */
	private function normalizedDefault(): mixed {
		if ($this->type !== Types::DECIMAL || ! is_scalar($this->default) || preg_match('/^(-?\\d+)(?:\\.(\\d*))?$/', (string) $this->default, $parts) !== 1) {
			return $this->default;
		}
		$scale    = (int) ($this->options['scale'] ?? 0);
		$fraction = $parts[2] ?? '';

		if (strlen($fraction) > $scale) {
			return $this->default; // More precision than the column stores; let the comparator surface it.
		}
		$fraction = str_pad($fraction, $scale, '0');

		return $scale === 0 ? $parts[1] : $parts[1] . '.' . $fraction;
	}

	private function isTimestamp(): bool {
		return $this->type === Types::DATETIME_MUTABLE || $this->type === Types::DATETIME_IMMUTABLE;
	}

	private function currentTimestamp(): string {
		// Explicit precision 0 and omitted precision are the same column; the database reports both as CURRENT_TIMESTAMP.
		return ($this->precision ?? 0) === 0 ? 'CURRENT_TIMESTAMP' : 'CURRENT_TIMESTAMP(' . $this->precision . ')';
	}

	/**
	 * @return non-empty-string
	 */
	private function timestampDefinition(): string {
		$sql = ($this->precision ?? 0) === 0 ? 'DATETIME' : 'DATETIME(' . $this->precision . ')';
		$sql .= $this->nullable ? ' DEFAULT NULL' : ' NOT NULL';

		if ($this->currentDefault) {
			$sql .= ' DEFAULT ' . $this->currentTimestamp();
		} elseif ($this->hasDefault && $this->default !== null) {
			$sql .= " DEFAULT '" . addslashes((string) $this->default) . "'";
		}

		if ($this->currentOnUpdate) {
			$sql .= ' ON UPDATE ' . $this->currentTimestamp();
		}

		if ($this->comment !== null) {
			$sql .= " COMMENT '" . addslashes($this->comment) . "'";
		}

		return $sql;
	}
}
