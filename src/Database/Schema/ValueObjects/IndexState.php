<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Schema\ValueObjects;

use StellarWP\Foundation\Database\Table\Index;
use StellarWP\Foundation\Database\Table\IndexType;

/**
 * Describes the normalized name, type, and ordered columns of one database index.
 *
 * @internal Used by schema reconciliation to compare inspected indexes.
 */
final readonly class IndexState
{
	/**
	 * Index types reported by physical inspection but not declared by Blueprint.
	 */
	public const string FULLTEXT = 'fulltext';

	public const string SPATIAL = 'spatial';
	public const string RTREE   = 'rtree';

	/**
	 * Capture a normalized physical or declared index definition.
	 *
	 * @param list<string> $columns
	 */
	public function __construct(
		public string $name,
		private string $type,
		private array $columns
	) {
	}

	/**
	 * Normalize a declared table index for comparison with physical metadata.
	 */
	public static function fromDefinition(Index $index): self {
		$name = $index->isPrimary() ? 'PRIMARY' : $index->name;
		$type = match (true) {
			$index->isPrimary() => IndexType::PRIMARY,
			$index->isUnique()  => IndexType::UNIQUE,
			default             => IndexType::KEY,
		};

		return new self(
			$name,
			$type,
			array_map(strtolower(...), $index->columns)
		);
	}

	/**
	 * Determine whether another normalized index has the same definition.
	 */
	public function hasSameDefinitionAs(self $other): bool {
		return strcasecmp($this->name, $other->name) === 0
			&& $this->type                              === $other->type
			&& $this->columns                           === $other->columns;
	}

	/**
	 * Format the index type and columns for schema reconciliation diagnostics.
	 */
	public function describe(): string {
		return sprintf('%s (%s)', strtoupper($this->type), implode(', ', $this->columns));
	}
}
