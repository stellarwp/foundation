<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Table\Blueprint;
use StellarWP\Foundation\Database\Table\IndexType;

final readonly class IndexReconciliationTable implements Table
{
	/**
	 * @param non-empty-list<string> $indexColumns
	 */
	public function __construct(
		private string $unprefixedName,
		private bool $includeIndex,
		private array $indexColumns = ['email'],
		private string $indexType = IndexType::UNIQUE
	) {
	}

	public function unprefixedName(): string {
		return $this->unprefixedName;
	}

	public function blueprint(): Blueprint {
		$definition = Blueprint::for($this);

		$definition->bigIncrements('id');
		$definition->string('email');
		$definition->string('tenant');

		if (! $this->includeIndex) {
			return $definition;
		}

		if ($this->indexType === IndexType::UNIQUE) {
			return $definition->unique('email_unique', ...$this->indexColumns);
		}

		return $definition->index('email_unique', ...$this->indexColumns);
	}
}
