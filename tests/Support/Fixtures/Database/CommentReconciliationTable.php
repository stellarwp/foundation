<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Table\Blueprint;
use StellarWP\Foundation\Database\Table\ColumnDefinition;

/**
 * Defines commented columns with representative supported attributes for reconciliation tests.
 */
final readonly class CommentReconciliationTable implements Table
{
	public function __construct(
		private string $unprefixedName,
		private ?string $comment
	) {
	}

	public function unprefixedName(): string {
		return $this->unprefixedName;
	}

	public function blueprint(): Blueprint {
		$table = Blueprint::for($this);

		$this->comment($table->bigIncrements('id'));
		$this->comment($table->string('description', 100)->nullable()->default('fallback'));

		return $table;
	}

	private function comment(ColumnDefinition $column): void {
		if ($this->comment !== null) {
			$column->comment($this->comment);
		}
	}
}
