<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query\ValueObjects;

/**
 * An immutable query snapshot used by terminal operations.
 *
 * @internal
 */
final readonly class QueryState
{
	/**
	 * Capture state without exposing a mutable builder to the compiler.
	 *
	 * @param list<Selection> $selection
	 * @param list<Fragment>  $joins
	 * @param list<string>    $groups
	 * @param list<string>    $orders
	 */
	public function __construct(
		public TableReference $table,
		public array $selection,
		public array $joins,
		public Fragment $where,
		public bool $distinct,
		public array $groups,
		public Fragment $having,
		public array $orders,
		public ?int $limit,
		public ?int $offset,
	) {
	}
}
