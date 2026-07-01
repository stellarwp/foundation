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

	public function test_it_defines_queue_style_columns_with_modifiers(): void {
		$definition = TableDefinition::for(new TestTable('queue_table', 'wp_queue'))
			->bigIncrements('id')
			->string('queue', 255)
			->string('task_handler', 255)
			->longText('args')
			->integer('priority', 3)->nullable()
			->dateTime('run_after')->default('0000-00-00 00:00:00')
			->integer('taken')->default(0)
			->integer('done')->nullable()->default(0)
			->tinyInteger('tries')->unsigned()->default(0)
			->tinyInteger('failed', 1)->unsigned()->default(false)
			->index('done', 'done')
			->index('taken_failed', 'taken', 'failed')
			->index('taken_failed_done', 'taken', 'failed', 'done');

		$this->assertSame([
			'`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT',
			'`queue` varchar(255) NOT NULL',
			'`task_handler` varchar(255) NOT NULL',
			'`args` longtext NOT NULL',
			'`priority` int(3) NULL',
			"`run_after` datetime NOT NULL DEFAULT '0000-00-00 00:00:00'",
			'`taken` int(10) NOT NULL DEFAULT 0',
			'`done` int(10) NULL DEFAULT 0',
			'`tries` tinyint(3) unsigned NOT NULL DEFAULT 0',
			'`failed` tinyint(1) unsigned NOT NULL DEFAULT 0',
		], array_map(static fn ($column): string => $column->sql(), $definition->columns()));

		$this->assertCount(4, $definition->indexes());
		$this->assertSame([], $definition->validationErrors());
	}

	public function test_it_defines_less_common_column_helpers(): void {
		$definition = TableDefinition::for(new TestTable('reports_table', 'wp_reports'))
			->bigInteger('remote_id')->unsigned()
			->string('status')->nullable()->notNull()->default('draft')
			->text('payload')->extra('COMMENT \'json payload\'');

		$this->assertSame([
			'`remote_id` bigint(20) unsigned NOT NULL',
			"`status` varchar(191) NOT NULL DEFAULT 'draft'",
			"`payload` text NOT NULL COMMENT 'json payload'",
		], array_map(static fn ($column): string => $column->sql(), $definition->columns()));
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

	public function test_it_rejects_column_modifiers_without_a_column(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A column modifier must follow a column definition.');

		TableDefinition::for(new TestTable('reports_table', 'wp_reports'))->nullable();
	}

	public function test_it_rejects_column_modifiers_after_index_definitions(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A column modifier must follow a column definition.');

		TableDefinition::for(new TestTable('reports_table', 'wp_reports'))
			->string('status')
			->index('status', 'status')
			->default('draft');
	}

	public function test_it_reports_duplicate_primary_keys(): void {
		$definition = TableDefinition::for(new TestTable('reports_table', 'wp_reports'))
			->bigIncrements('id')
			->string('status')
			->primary('status');

		$this->assertContains('A table can define only one primary key.', $definition->validationErrors());
	}

	public function test_it_reports_duplicate_index_names(): void {
		$definition = TableDefinition::for(new TestTable('reports_table', 'wp_reports'))
			->bigIncrements('id')
			->string('status')
			->string('type')
			->index('status_lookup', 'status')
			->index('status_lookup', 'type');

		$this->assertContains('Index status_lookup is defined more than once.', $definition->validationErrors());
	}
}
