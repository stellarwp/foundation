<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Table\Blueprint;

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

	public function unprefixedName(): string {
		return $this->unprefixedName;
	}

	public function blueprint(): Blueprint {
		$table = Blueprint::for($this);

		$table->bigIncrements('id');
		$description = $table->text('description');

		if ($this->comment !== null) {
			$description->comment($this->comment);
		}

		return $table;
	}
}
