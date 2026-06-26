<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

/**
 * Defines one database table column.
 */
final readonly class Column
{
	public function __construct(
		public string $name,
		public string $type,
		public ?int $length = null,
		public bool $unsigned = false,
		public bool $nullable = false,
		public mixed $default = null,
		public string $extra = '',
		public bool $hasDefault = false
	) {
	}

	public function sql(): string {
		$sql = sprintf(
			'`%s` %s%s%s%s',
			str_replace('`', '``', $this->name),
			$this->type,
			$this->length === null ? '' : sprintf('(%d)', $this->length),
			$this->unsigned ? ' unsigned' : '',
			$this->nullable ? ' NULL' : ' NOT NULL'
		);

		if ($this->default !== null || $this->hasDefault) {
			$sql .= sprintf(' DEFAULT %s', $this->formatDefault($this->default));
		}

		if ($this->extra !== '') {
			$sql .= ' ' . $this->extra;
		}

		return $sql;
	}

	public function unsigned(bool $unsigned = true): self {
		return new self(
			$this->name,
			$this->type,
			$this->length,
			$unsigned,
			$this->nullable,
			$this->default,
			$this->extra,
			$this->hasDefault
		);
	}

	public function nullable(bool $nullable = true): self {
		return new self(
			$this->name,
			$this->type,
			$this->length,
			$this->unsigned,
			$nullable,
			$this->default,
			$this->extra,
			$this->hasDefault
		);
	}

	public function default(mixed $default): self {
		return new self(
			$this->name,
			$this->type,
			$this->length,
			$this->unsigned,
			$default === null ? true : $this->nullable,
			$default,
			$this->extra,
			true
		);
	}

	public function extra(string $extra): self {
		return new self(
			$this->name,
			$this->type,
			$this->length,
			$this->unsigned,
			$this->nullable,
			$this->default,
			$extra,
			$this->hasDefault
		);
	}

	public function autoIncrement(): self {
		if (preg_match('/(?:^|\s)AUTO_INCREMENT(?:\s|$)/i', $this->extra) === 1) {
			return $this;
		}

		return $this->extra(trim($this->extra . ' AUTO_INCREMENT'));
	}

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

		return "'" . addslashes((string) $default) . "'";
	}
}
