<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Table\Table;
use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Provides a concrete table gateway for database table tests.
 */
final readonly class TestDatabaseTable extends Table
{
	public function id(): string {
		return 'test_database_table';
	}

	public function definition(): TableDefinition {
		return TableDefinition::for($this)
			->bigIncrements('id');
	}
}
