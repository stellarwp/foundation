<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Table\Blueprint;

final readonly class ManagedTimestampTable implements Table
{
	public function __construct(
		private string $unprefixedName,
		private int $precision
	) {
	}

	public function unprefixedName(): string {
		return $this->unprefixedName;
	}

	public function blueprint(): Blueprint {
		$table = Blueprint::for($this);
		$table->bigIncrements('id');
		$table->string('status', 20)->default('draft');
		$table->dateTime('created_at', $this->precision)->useCurrent();
		$table->dateTime('updated_at', $this->precision)->nullable()->useCurrent()->useCurrentOnUpdate();
		$table->timestamp('created_stamp', $this->precision)->useCurrent();
		$table->timestamp('updated_stamp', $this->precision)->nullable()->useCurrent()->useCurrentOnUpdate();
		$table->timestamp('processed_at', $this->precision)->nullable()->default(null)->useCurrentOnUpdate();

		return $table;
	}
}
