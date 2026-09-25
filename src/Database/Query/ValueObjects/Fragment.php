<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query\ValueObjects;

/**
 * A SQL fragment and its positional values, in placeholder order.
 *
 * @internal
 */
final readonly class Fragment
{
	/**
	 * Carry part of a query or a complete statement without executing it.
	 *
	 * @param list<mixed> $bindings
	 */
	public function __construct(
		public string $sql,
		public array $bindings = [],
	) {
	}
}
