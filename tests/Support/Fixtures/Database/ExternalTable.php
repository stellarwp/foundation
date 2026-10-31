<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Table\Table;

/**
 * Represents an existing table whose schema is not managed by Foundation.
 */
final readonly class ExternalTable extends Table
{
	public function unprefixedName(): string {
		return 'external_reports';
	}
}
