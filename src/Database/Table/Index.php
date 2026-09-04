<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Traits\QuotesSqlIdentifiers;

/**
 * Defines one database table index.
 */
final readonly class Index
{
	use QuotesSqlIdentifiers;

	/**
	 * @var IndexType::PRIMARY|IndexType::UNIQUE|IndexType::KEY
	 */
	private string $type;

	/**
	 * @param list<string> $columns
	 *
	 * @throws InvalidArgumentException When the index name, columns, or type are invalid.
	 */
	public function __construct(
		public string $name,
		public array $columns,
		string $type = IndexType::KEY
	) {
		if (trim($this->name) === '') {
			throw new InvalidArgumentException('Index name cannot be blank.');
		}

		if ($this->columns === []) {
			throw new InvalidArgumentException('An index must define at least one column.');
		}

		foreach ($this->columns as $column) {
			if (trim($column) === '') {
				throw new InvalidArgumentException(sprintf('Index %s contains a blank column name.', $this->name));
			}
		}

		if (! in_array($type, [IndexType::PRIMARY, IndexType::UNIQUE, IndexType::KEY], true)) {
			throw new InvalidArgumentException(sprintf('Unsupported index type "%s".', $type));
		}

		$this->type = $type;
	}

	/**
	 * Determine whether this declaration defines the table's primary key.
	 */
	public function isPrimary(): bool {
		return $this->type === IndexType::PRIMARY;
	}

	/**
	 * Determine whether this declaration defines a unique secondary index.
	 */
	public function isUnique(): bool {
		return $this->type === IndexType::UNIQUE;
	}

	/**
	 * Render this index declaration in the format expected by dbDelta().
	 */
	public function sql(): string {
		$columns = implode(', ', array_map(
			fn (string $column): string => $this->quoteSqlIdentifier($column),
			$this->columns
		));

		// dbDelta() requires two spaces between PRIMARY KEY and the column list.
		return match ($this->type) {
			IndexType::PRIMARY => sprintf('PRIMARY KEY  (%s)', $columns),
			IndexType::UNIQUE  => sprintf('UNIQUE KEY %s (%s)', $this->quotedName(), $columns),
			IndexType::KEY     => sprintf('KEY %s (%s)', $this->quotedName(), $columns),
		};
	}

	/**
	 * Quote the index name for use in a SQL declaration.
	 */
	private function quotedName(): string {
		return $this->quoteSqlIdentifier($this->name);
	}
}
