<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table\ValueObjects;

/**
 * Selects the database's current timestamp as a column default.
 *
 * Fractional-second precision comes from the completed column declaration.
 */
final readonly class CurrentTimestamp
{
}
