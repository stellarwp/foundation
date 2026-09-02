<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\TableDefinition;

final readonly class SchemaReconciliationTable implements Table
{
	public function __construct(
		private string $unprefixedName,
		private int $attemptsDefault,
		private bool $completedAtNullable
	) {
	}

	public function id(): string {
		return 'schema_reconciliation_table';
	}

	public function unprefixedName(): string {
		return $this->unprefixedName;
	}

	public function definition(): TableDefinition {
		$table = TableDefinition::for($this);

		$table->bigIncrements('id');
		$table->integer('attempts')->default($this->attemptsDefault);
		$table->dateTime('completed_at')->nullable($this->completedAtNullable);
		$table->string('label')->default('');
		$table->column(new Column('ratio', 'decimal(10,2)'))->default(1.25);
		$table->column(new Column('enabled', 'bit', 1))->default(true);

		return $table;
	}
}
