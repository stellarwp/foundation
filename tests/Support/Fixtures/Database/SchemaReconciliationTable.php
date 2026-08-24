<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\TableDefinition;

final readonly class SchemaReconciliationTable implements Table
{
	public function __construct(
		private string $table,
		private int $attemptsDefault,
		private bool $completedAtNullable
	) {
	}

	public function id(): string {
		return 'schema_reconciliation_table';
	}

	public function name(): string {
		return $this->table;
	}

	public function definition(): TableDefinition {
		return TableDefinition::for($this)
		                      ->bigIncrements('id')
		                      ->integer('attempts')->default($this->attemptsDefault)
		                      ->dateTime('completed_at')->nullable($this->completedAtNullable)
		                      ->string('label')->default('')
		                      ->column(new Column('ratio', 'decimal(10,2)', default: 1.25))
		                      ->column(new Column('enabled', 'bit', 1, default: true));
	}
}
