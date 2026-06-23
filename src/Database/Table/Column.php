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
		public string $extra = ''
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

		if ($this->default !== null) {
			$sql .= sprintf(' DEFAULT %s', $this->formatDefault($this->default));
		}

		if ($this->extra !== '') {
			$sql .= ' ' . $this->extra;
		}

		return $sql;
	}

	private function formatDefault(mixed $default): string {
		if (is_int($default) || is_float($default)) {
			return (string) $default;
		}

		return "'" . addslashes((string) $default) . "'";
	}
}
