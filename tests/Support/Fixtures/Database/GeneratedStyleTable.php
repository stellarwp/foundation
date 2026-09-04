<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\ManagedTable;
use StellarWP\Foundation\Database\Table\Table;
use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Mirrors the constructorless table shape emitted by the database generator.
 */
final readonly class GeneratedStyleTable extends Table implements ManagedTable
{
	public function unprefixedName(): string {
		return 'generated_style';
	}

	public function definition(): TableDefinition {
		$table = TableDefinition::for($this);
		$table->bigIncrements('id');

		return $table;
	}
}
