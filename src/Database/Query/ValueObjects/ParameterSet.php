<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query\ValueObjects;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;

/**
 * Values and their matching DBAL parameter types, including explicit conversions.
 *
 * @internal
 */
final readonly class ParameterSet
{
	/**
	 * Associate values and types by placeholder position or name.
	 *
	 * @param array<int<0, max>|string, mixed>                                        $values
	 * @param array<int<0, max>|string, string|Type|ParameterType|ArrayParameterType> $types
	 */
	public function __construct(
		public array $values,
		public array $types,
	) {
	}
}
