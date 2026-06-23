<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Table\TableDefinition;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestTable;
use StellarWP\Foundation\Tests\TestCase;

final class TableDefinitionTest extends TestCase
{
	public function test_it_collects_columns_and_indexes(): void {
		$definition = TableDefinition::for(new TestTable('reports_table', 'wp_reports'))
			->bigIncrements('id')
			->string('status', 20)
			->text('payload')
			->dateTime('created_at')
			->index('status', 'status');

		$this->assertCount(4, $definition->columns());
		$this->assertCount(2, $definition->indexes());
		$this->assertSame([], $definition->validationErrors());
	}

	public function test_it_rejects_indexes_that_reference_missing_columns(): void {
		$definition = TableDefinition::for(new TestTable('reports_table', 'wp_reports'))
			->string('status', 20)
			->index('missing_index', 'missing');

		$this->assertSame(['Index missing_index references missing column missing.'], $definition->validationErrors());

		$this->expectException(InvalidArgumentException::class);

		$definition->assertValid();
	}

	public function test_it_rejects_tables_without_columns(): void {
		$definition = TableDefinition::for(new TestTable('reports_table', 'wp_reports'));

		$this->assertSame(['Table reports_table does not define any columns.'], $definition->validationErrors());
	}

	public function test_it_rejects_indexes_without_columns(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('An index must define at least one column.');

		TableDefinition::for(new TestTable('reports_table', 'wp_reports'))->index('empty_index');
	}
}
