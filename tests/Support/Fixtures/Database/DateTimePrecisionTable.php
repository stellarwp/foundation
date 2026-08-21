<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Table\TableDefinition;

final readonly class DateTimePrecisionTable implements Table
{
	public function __construct(
		private string $table
	) {
	}

	public function id(): string {
		return 'datetime_precision_table';
	}

	public function name(): string {
		return $this->table;
	}

	public function definition(): TableDefinition {
		return TableDefinition::for($this)
			->bigIncrements('id')
			->dateTime('occurred_at', 0);
	}
}
