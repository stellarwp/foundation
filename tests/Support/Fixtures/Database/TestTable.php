<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Table\Blueprint;

final class TestTable implements Table
{
	public function __construct(
		private readonly string $unprefixedName
	) {
	}

	public function unprefixedName(): string {
		return $this->unprefixedName;
	}

	public function blueprint(): Blueprint {
		$table = Blueprint::for($this);
		$table->bigIncrements('id');

		return $table;
	}
}
