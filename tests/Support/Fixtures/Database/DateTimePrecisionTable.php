<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\ManagedTable;
use StellarWP\Foundation\Database\Table\TableDefinition;

final readonly class DateTimePrecisionTable implements ManagedTable
{
	public function __construct(
		private string $unprefixedName
	) {
	}

	public function unprefixedName(): string {
		return $this->unprefixedName;
	}

	public function definition(): TableDefinition {
		$table = TableDefinition::for($this);

		$table->bigIncrements('id');
		$table->dateTime('occurred_at', 0);

		return $table;
	}
}
