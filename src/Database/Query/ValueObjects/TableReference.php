<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query\ValueObjects;

use InvalidArgumentException;

/**
 * A physical table identity captured at the current WordPress site.
 */
final readonly class TableReference
{
	/**
	 * Capture a resolved name without applying another WordPress prefix.
	 *
	 * @throws InvalidArgumentException When the physical name or alias is invalid.
	 */
	public function __construct(
		public string $name,
		public ?string $alias = null,
	) {
		if (preg_match('/\A[A-Za-z0-9_]+\z/', $name) !== 1 || strlen($name) > 64) {
			throw new InvalidArgumentException('Physical table names must contain at most 64 ASCII letters, numbers, or underscores.');
		}

		if ($alias !== null && preg_match('/\A[A-Za-z0-9_]+\z/', $alias) !== 1) {
			throw new InvalidArgumentException('Invalid table alias: ' . $alias);
		}
	}

	/**
	 * Return this table with a query-local alias.
	 *
	 * @throws InvalidArgumentException When the alias is invalid.
	 */
	public function as(string $alias): self {
		return new self($this->name, $alias);
	}
}
