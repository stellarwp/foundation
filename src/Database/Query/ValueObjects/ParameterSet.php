<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query\ValueObjects;

use Doctrine\DBAL\ParameterType;

/**
 * Normalized values and their matching DBAL parameter types.
 *
 * @internal
 */
final readonly class ParameterSet
{
	/**
	 * Keep values and types in the same placeholder order.
	 *
	 * @param list<int|string|null> $values
	 * @param list<ParameterType>   $types
	 */
	public function __construct(
		public array $values,
		public array $types,
	) {
	}
}
