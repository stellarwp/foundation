<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Table\Blueprint;

final readonly class DateTimePrecisionTable implements Table
{
	public function __construct(
		private string $unprefixedName
	) {
	}

	public function unprefixedName(): string {
		return $this->unprefixedName;
	}

	public function blueprint(): Blueprint {
		$table = Blueprint::for($this);

		$table->bigIncrements('id');
		$table->dateTime('occurred_at', 0);

		return $table;
	}
}
