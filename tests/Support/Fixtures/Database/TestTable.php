<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Table\TableDefinition;

final class TestTable implements Table
{
	public function __construct(
		private readonly string $id,
		private readonly string $unprefixedName
	) {
	}

	public function id(): string {
		return $this->id;
	}

	public function unprefixedName(): string {
		return $this->unprefixedName;
	}

	public function definition(): TableDefinition {
		$table = TableDefinition::for($this);
		$table->bigIncrements('id');

		return $table;
	}
}
