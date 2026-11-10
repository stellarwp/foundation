<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query\Upsert\Contracts;

/**
 * Supplies the server-specific suffix for a multi-row upsert.
 *
 * @internal
 */
interface Builder
{
	/**
	 * Build the upsert clause for validated, unquoted insert column names.
	 *
	 * @param non-empty-list<string> $columns
	 */
	public function build(array $columns, string $table): string;
}
