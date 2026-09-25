<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative;

use StellarWP\Foundation\Database\Contracts\Table;

/**
 * Application table identity for the declarative runner tests.
 */
final readonly class EntriesTable implements Table
{
	public function __construct(
		private string $unprefixedName,
	) {
	}

	public function unprefixedName(): string {
		return $this->unprefixedName;
	}
}
