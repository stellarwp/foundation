<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\DatabaseScope;
use StellarWP\Foundation\Database\Exceptions\DatabaseContextChanged;

/**
 * Provides a mutable database scope for migration operation tests.
 */
final class TestDatabaseScope implements DatabaseScope
{
	public int $currentId = 1;

	public int $assertions = 0;

	public function resolveTableName(string $unprefixedTableName): string {
		return $unprefixedTableName;
	}

	public function capture(): int {
		return $this->currentId;
	}

	public function assertCurrent(int $scopeId): void {
		$this->assertions++;

		if ($this->currentId !== $scopeId) {
			throw new DatabaseContextChanged('test context ' . $scopeId, 'test context ' . $this->currentId);
		}
	}
}
