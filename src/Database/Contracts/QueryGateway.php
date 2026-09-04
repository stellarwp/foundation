<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

/**
 * Supplies the capabilities required to build and execute table-scoped queries.
 */
interface QueryGateway extends QueryReader, TableNameResolver
{
	/**
	 * Quote one trusted identifier for use in a generated SQL query.
	 */
	public function quoteIdentifier(string $identifier): string;
}
