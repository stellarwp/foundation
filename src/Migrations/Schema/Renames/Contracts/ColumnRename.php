<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema\Renames\Contracts;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Table;

/**
 * Preserve a column's definition while generating its rename for the active server.
 *
 * @internal
 */
interface ColumnRename
{
	/**
	 * Capture any additional live metadata required by this rename strategy.
	 *
	 * @throws Exception When metadata cannot be read.
	 */
	public function inspect(Table $table): Table;

	/**
	 * Generate one column rename against a live or simulated snapshot.
	 *
	 * @param non-empty-string $from
	 * @param non-empty-string $to
	 *
	 * @throws Exception When platform SQL cannot be generated.
	 *
	 * @return list<string> Statements in execution order.
	 */
	public function sql(Table $table, string $from, string $to): array;
}
