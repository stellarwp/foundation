<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Exceptions\DatabaseContextChanged;

/**
 * Resolves Foundation-owned database resources in the active WordPress scope.
 */
interface DatabaseScope
{
	/**
	 * Apply the current database scope to an unprefixed table name.
	 */
	public function resolveTableName(string $unprefixedTableName): string;

	/**
	 * Capture the identifier of the currently active database scope.
	 */
	public function capture(): int;

	/**
	 * Confirm that the captured database scope is still active.
	 *
	 * @throws DatabaseContextChanged When the active database context has changed.
	 */
	public function assertCurrent(int $scopeId): void;
}
