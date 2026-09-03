<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Defines a table with a configurable column comment for schema reconciliation tests.
 */
final readonly class CommentedTable implements Table
{
	public function __construct(
		private string $unprefixedName,
		private ?string $comment
	) {
	}

	public function id(): string {
		return 'commented_table';
	}

	public function unprefixedName(): string {
		return $this->unprefixedName;
	}

	public function definition(): TableDefinition {
		$table = TableDefinition::for($this);

		$table->bigIncrements('id');
		$description = $table->text('description');

		if ($this->comment !== null) {
			$description->comment($this->comment);
		}

		return $table;
	}
}
