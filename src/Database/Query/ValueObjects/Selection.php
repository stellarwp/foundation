<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query\ValueObjects;

/**
 * A selected SQL expression and the output information known during parsing.
 *
 * @internal
 */
final readonly class Selection
{
	/**
	 * Carry a bound expression with its lowercase output name or wildcard.
	 *
	 * A null name marks an opaque raw expression; wildcard names are '*'.
	 */
	public function __construct(
		public Fragment $fragment,
		public ?string $name = null,
		public bool $wildcard = false,
	) {
	}
}
