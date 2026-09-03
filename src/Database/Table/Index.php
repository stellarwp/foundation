<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

/**
 * Defines one database table index.
 */
final readonly class Index
{
	/**
	 * @param non-empty-list<string> $columns
	 */
	public function __construct(
		public string $name,
		public array $columns,
		public string $type = IndexType::KEY
	) {
	}

	public function sql(): string {
		$columns = implode(', ', array_map(
			static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`',
			$this->columns
		));

		return match ($this->type) {
			IndexType::PRIMARY => sprintf('PRIMARY KEY  (%s)', $columns),
			IndexType::UNIQUE  => sprintf('UNIQUE KEY %s (%s)', $this->quotedName(), $columns),
			default            => sprintf('KEY %s (%s)', $this->quotedName(), $columns),
		};
	}

	private function quotedName(): string {
		return '`' . str_replace('`', '``', $this->name) . '`';
	}
}
