<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

/**
 * Performs ordinary queries and writes against application table objects.
 */
interface TableGateway extends QueryExecutor, QueryGateway, SqlDialect, TableWriter
{
}
