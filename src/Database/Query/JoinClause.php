<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Query\ValueObjects\Fragment;

/**
 * Combine column relationships and bound value conditions for a join.
 */
final class JoinClause extends WhereGroup
{
	/**
	 * Require a relationship between two columns.
	 *
	 * @throws InvalidArgumentException When a column or comparison operator is invalid.
	 */
	public function on(string $first, string $operator, string $second): self {
		$condition = new Fragment(
			$this->quoter->column($first) . ' ' . $this->operator($operator) . ' ' . $this->quoter->column($second),
		);

		return $this->add($condition, 'AND');
	}

	/**
	 * Permit an alternative relationship between two columns.
	 *
	 * @throws InvalidArgumentException When a column or comparison operator is invalid.
	 */
	public function orOn(string $first, string $operator, string $second): self {
		$condition = new Fragment(
			$this->quoter->column($first) . ' ' . $this->operator($operator) . ' ' . $this->quoter->column($second),
		);

		return $this->add($condition, 'OR');
	}

	/**
	 * Preserve column comparisons inside nested join conditions.
	 *
	 * @internal
	 */
	protected function newGroup(): self {
		return new self($this->quoter);
	}
}
