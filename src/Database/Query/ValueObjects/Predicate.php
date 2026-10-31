<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query\ValueObjects;

/**
 * A condition and its relationship to the preceding condition.
 *
 * @internal
 */
final readonly class Predicate
{
	/**
	 * Keep each condition's SQL and values together.
	 */
	public function __construct(
		public Fragment $fragment,
		public string $boolean,
	) {
	}
}
