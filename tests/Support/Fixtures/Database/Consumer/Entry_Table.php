<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database\Consumer;

use StellarWP\Foundation\Database\Table\Table;

/**
 * An application table declares its stable name and inherits provider wiring.
 */
final readonly class Entry_Table extends Table
{
	/**
	 * Identify this application's entries before the current site's prefix.
	 */
	public function unprefixedName(): string {
		return 'foundation_consumer_entries';
	}
}
