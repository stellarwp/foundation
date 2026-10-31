<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Table\Table;

/**
 * An application table with the constructorless shape emitted by the generator.
 */
final readonly class RecoveryTable extends Table
{
	/**
	 * {@inheritDoc}
	 */
	public function unprefixedName(): string {
		return 'foundation_migration_recovery_items';
	}
}
