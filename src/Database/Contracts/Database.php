<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

/**
 * Convenient aggregate for Foundation's complete WordPress database API.
 *
 * New optional capabilities should use separate contracts rather than adding
 * methods to this stable aggregate.
 */
interface Database extends CharsetCollationProvider, SchemaInspector, TableGateway
{
}
