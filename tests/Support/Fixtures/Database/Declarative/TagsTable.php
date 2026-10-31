<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Declarative;

use StellarWP\Foundation\Database\Contracts\Table;

/**
 * Second application table for multi-table planning scenarios.
 */
final readonly class TagsTable implements Table
{
	public function __construct(
		private string $unprefixedName,
	) {
	}

	public function unprefixedName(): string {
		return $this->unprefixedName;
	}
}
