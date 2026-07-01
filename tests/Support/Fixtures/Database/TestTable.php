<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Table\TableDefinition;

final class TestTable implements Table
{
	public function __construct(
		private readonly string $id,
		private readonly string $name
	) {
	}

	public function id(): string {
		return $this->id;
	}

	public function name(): string {
		return $this->name;
	}

	public function definition(): TableDefinition {
		return TableDefinition::for($this)
			->bigIncrements('id');
	}
}
