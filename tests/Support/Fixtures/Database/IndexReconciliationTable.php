<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\ManagedTable;
use StellarWP\Foundation\Database\Table\IndexType;
use StellarWP\Foundation\Database\Table\TableDefinition;

final readonly class IndexReconciliationTable implements ManagedTable
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

	public function definition(): TableDefinition {
		$definition = TableDefinition::for($this);

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
