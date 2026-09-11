<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Database\Table\Blueprint;
use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\ColumnDefinition;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestTable;
use StellarWP\Foundation\Tests\TestCase;

final class BlueprintTest extends TestCase
{
	public function test_column_modifiers_are_scoped_to_the_returned_column_definition(): void {
		$definition = Blueprint::for(new TestTable('reports'));
		$status     = $definition->string('status', 20);

		$definition->string('category', 40);
		$status->nullable()->default('draft');

		$this->assertInstanceOf(ColumnDefinition::class, $status);
		$this->assertSame([
			"`status` varchar(20) NULL DEFAULT 'draft'",
			'`category` varchar(40) NOT NULL',
		], array_map(static fn ($column): string => $column->sql(), $definition->columns()));
	}

	public function test_it_collects_columns_and_indexes(): void {
		$table      = new TestTable('reports');
		$definition = Blueprint::for($table);

		$definition->bigIncrements('id');
		$definition->string('status', 20);
		$definition->text('payload');
		$definition->dateTime('created_at');
		$definition->index('status', 'status');

		$this->assertCount(4, $definition->columns());
		$this->assertCount(2, $definition->indexes());
		$this->assertSame($table, $definition->table());
		$this->assertSame([], $definition->errorsForCreate());
	}

	public function test_it_records_explicit_index_removals_for_an_alteration(): void {
		$blueprint = Blueprint::for(new TestTable('reports'));

		$blueprint->dropIndex('legacy_status');

		$this->assertSame(['legacy_status'], $blueprint->droppedIndexes());
		$this->assertSame([], $blueprint->errorsForAlter());
	}

	public function test_it_directs_primary_key_removals_to_explicit_schema_sql(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Use Schema::execute() with explicit ALTER TABLE SQL to remove the PRIMARY index.');

		Blueprint::for(new TestTable('reports'))->dropIndex('PRIMARY');
	}

	public function test_it_records_explicit_column_removals_for_an_alteration(): void {
		$blueprint = Blueprint::for(new TestTable('reports'));

		$blueprint->dropColumn('legacy_status');
		$blueprint->dropColumn('legacy_status');

		$this->assertSame(['legacy_status'], $blueprint->droppedColumns());
		$this->assertSame([], $blueprint->errorsForAlter());
	}

	public function test_it_rejects_declaring_and_removing_the_same_column(): void {
		$blueprint = Blueprint::for(new TestTable('reports'));
		$blueprint->string('status');
		$blueprint->dropColumn('status');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot be declared and removed');

		$blueprint->assertValidForAlter();
	}

	public function test_it_allows_an_alteration_index_to_reference_an_existing_table_column(): void {
		$blueprint = Blueprint::for(new TestTable('reports'));

		$blueprint->index('status', 'status');

		$this->assertSame([], $blueprint->errorsForAlter());
	}

	public function test_it_rejects_an_alteration_index_that_references_a_removed_column(): void {
		$blueprint = Blueprint::for(new TestTable('reports'));

		$blueprint->dropColumn('status');
		$blueprint->index('status_lookup', 'status');

		$this->assertSame([
			'Index status_lookup references column status, which is removed by the same blueprint.',
		], $blueprint->errorsForAlter());
	}

	public function test_it_rejects_index_removals_from_a_creation_blueprint(): void {
		$blueprint = Blueprint::for(new TestTable('reports'));
		$blueprint->bigIncrements('id');
		$blueprint->dropIndex('legacy_status');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A table creation blueprint cannot remove columns or indexes.');

		$blueprint->assertValidForCreate();
	}

	public function test_it_rejects_an_empty_alteration_blueprint(): void {
		$blueprint = Blueprint::for(new TestTable('reports'));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Table reports does not define any schema changes.');

		$blueprint->assertValidForAlter();
	}

	public function test_it_marks_only_explicit_column_changes_for_modification(): void {
		$blueprint = Blueprint::for(new TestTable('reports'));

		$blueprint->string('status', 20)->change();
		$blueprint->dateTime('published_at')->nullable();

		$this->assertSame(['published_at'], array_map(
			static fn ($column): string => $column->name,
			$blueprint->addedColumns()
		));
		$this->assertSame(['status'], array_map(
			static fn ($column): string => $column->name,
			$blueprint->changedColumns()
		));
	}

	public function test_it_quotes_index_names_and_columns_and_escapes_embedded_backticks(): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$definition->string('report`status');
		$definition->index('report`lookup', 'report`status');

		$this->assertSame(
			'KEY `report``lookup` (`report``status`)',
			$definition->indexes()[0]->sql()
		);
	}

	public function test_it_defines_queue_style_columns_with_modifiers(): void {
		$definition = Blueprint::for(new TestTable('queue'));

		$definition->bigIncrements('id');
		$definition->string('queue', 255);
		$definition->string('task_handler', 255);
		$definition->longText('args');
		$definition->integer('priority', 3)->nullable();
		$definition->dateTime('run_after')->default('0000-00-00 00:00:00');
		$definition->integer('taken')->default(0);
		$definition->integer('done')->nullable()->default(0);
		$definition->tinyInteger('tries')->unsigned()->default(0);
		$definition->tinyInteger('failed', 1)->unsigned()->default(false);
		$definition->index('done', 'done');
		$definition->index('taken_failed', 'taken', 'failed');
		$definition->index('taken_failed_done', 'taken', 'failed', 'done');

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
		$this->assertSame([], $definition->errorsForCreate());
	}

	public function test_it_defines_less_common_column_helpers(): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$definition->bigInteger('remote_id')->unsigned();
		$definition->string('status')->nullable()->notNull()->default('draft');
		$definition->text('payload')->comment('json payload');

		$this->assertSame([
			'`remote_id` bigint(20) unsigned NOT NULL',
			"`status` varchar(191) NOT NULL DEFAULT 'draft'",
			"`payload` text NOT NULL COMMENT 'json payload'",
		], array_map(static fn ($column): string => $column->sql(), $definition->columns()));
	}

	/**
	 * @dataProvider temporalHelpers
	 *
	 * @param 'dateTime'|'timestamp' $helper
	 */
	#[DataProvider('temporalHelpers')]
	public function test_it_defines_temporal_precision_boundaries(string $helper, string $type): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$definition->{$helper}('omitted')->useCurrent();
		$definition->{$helper}('seconds', 0)->useCurrent();
		$definition->{$helper}('microseconds', 6)->useCurrent()->useCurrentOnUpdate();

		$this->assertSame([
			"`omitted` {$type} NOT NULL DEFAULT CURRENT_TIMESTAMP",
			"`seconds` {$type} NOT NULL DEFAULT CURRENT_TIMESTAMP",
			"`microseconds` {$type}(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)",
		], array_map(static fn ($column): string => $column->sql(), $definition->columns()));
		$this->assertSame([], $definition->errorsForCreate());
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function temporalHelpers(): array {
		return [
			'datetime'  => ['dateTime', 'datetime'],
			'timestamp' => ['timestamp', 'timestamp'],
		];
	}

	public function test_it_supports_update_only_nullable_and_custom_temporal_columns(): void {
		$definition = Blueprint::for(new TestTable('reports'));
		$definition->timestamp('processed_at', 3)->nullable()->default(null)->useCurrentOnUpdate();
		$definition->column(new Column('updated_at', 'datetime(6)'))->useCurrent()->useCurrentOnUpdate();

		$this->assertSame([
			'`processed_at` timestamp(3) NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(3)',
			'`updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)',
		], array_map(static fn ($column): string => $column->sql(), $definition->columns()));
		$this->assertSame([], $definition->errorsForCreate());
	}

	/**
	 * @dataProvider invalidTemporalPrecisionProvider
	 *
	 * @param 'dateTime'|'timestamp' $helper
	 */
	#[DataProvider('invalidTemporalPrecisionProvider')]
	public function test_it_rejects_invalid_temporal_precision(string $helper, int $precision): void {
		$this->expectException(InvalidArgumentException::class);

		Blueprint::for(new TestTable('reports'))->{$helper}('created_at', $precision);
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function invalidTemporalPrecisionProvider(): array {
		return [
			'datetime negative'       => ['dateTime', -1],
			'datetime above maximum'  => ['dateTime', 7],
			'timestamp negative'      => ['timestamp', -1],
			'timestamp above maximum' => ['timestamp', 7],
		];
	}

	public function test_it_rejects_a_current_timestamp_default_on_a_non_temporal_creation_column(): void {
		$definition = Blueprint::for(new TestTable('reports'));
		$definition->string('status')->useCurrent();

		$this->expectException(InvalidArgumentException::class);

		$definition->assertValidForCreate();
	}

	public function test_it_rejects_invalid_direct_temporal_precision_in_an_alteration(): void {
		$definition = Blueprint::for(new TestTable('reports'));
		$definition->column(new Column('updated_at', 'datetime(7)'))->useCurrentOnUpdate()->change();

		$this->expectException(InvalidArgumentException::class);

		$definition->assertValidForAlter();
	}

	public function test_it_validates_the_completed_default_rather_than_transient_modifiers(): void {
		$definition = Blueprint::for(new TestTable('reports'));
		$definition->string('status')->useCurrent()->default('pending');

		$this->assertSame([], $definition->errorsForCreate());
		$this->assertSame("`status` varchar(191) NOT NULL DEFAULT 'pending'", $definition->columns()[0]->sql());
	}

	public function test_it_rejects_indexes_that_reference_missing_columns(): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$definition->string('status', 20);
		$definition->index('missing_index', 'missing');

		$this->assertSame(['Index missing_index references missing column missing.'], $definition->errorsForCreate());

		$this->expectException(InvalidArgumentException::class);

		$definition->assertValidForCreate();
	}

	public function test_it_rejects_tables_without_columns(): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$this->assertSame(['Table reports does not define any columns.'], $definition->errorsForCreate());
	}

	public function test_it_rejects_invalid_final_column_states(): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$definition->bigIncrements('id')->nullable();
		$definition->dateTime('completed_at')->default(null);

		$this->assertSame([
			'Column id cannot be nullable because it uses AUTO_INCREMENT.',
			'Column completed_at cannot use DEFAULT NULL unless it is nullable.',
		], $definition->errorsForCreate());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Column id cannot be nullable because it uses AUTO_INCREMENT.');

		$definition->assertValidForCreate();
	}

	public function test_final_column_validation_is_independent_of_modifier_order(): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$definition->bigIncrements('id')->nullable()->notNull();
		$definition->dateTime('completed_at')->default(null)->nullable();

		$this->assertSame([], $definition->errorsForCreate());
	}

	public function test_it_rejects_an_unindexed_auto_increment_column(): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$definition->integer('sequence')->autoIncrement();

		$this->assertSame([
			'AUTO_INCREMENT column sequence must be the first column in an index.',
		], $definition->errorsForCreate());
	}

	public function test_it_rejects_an_unindexed_auto_increment_column_added_by_an_alteration(): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$definition->integer('sequence')->autoIncrement();

		$this->assertSame([
			'AUTO_INCREMENT column sequence must be the first column in an index.',
		], $definition->errorsForAlter());
	}

	public function test_it_rejects_multiple_auto_increment_columns(): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$definition->bigIncrements('id');
		$definition->integer('legacy_id')->autoIncrement();
		$definition->index('legacy_id', 'legacy_id');

		$this->assertSame([
			'A table can define only one AUTO_INCREMENT column.',
		], $definition->errorsForCreate());
	}

	public function test_it_requires_an_auto_increment_column_to_lead_its_index(): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$definition->string('tenant');
		$definition->integer('sequence')->autoIncrement();
		$definition->index('tenant_sequence', 'tenant', 'sequence');

		$this->assertSame([
			'AUTO_INCREMENT column sequence must be the first column in an index.',
		], $definition->errorsForCreate());
	}

	public function test_it_accepts_an_auto_increment_column_leading_a_secondary_index(): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$definition->integer('sequence')->autoIncrement();
		$definition->string('tenant');
		$definition->index('sequence_tenant', 'sequence', 'tenant');

		$this->assertSame([], $definition->errorsForCreate());
	}

	public function test_it_rejects_indexes_without_columns(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('An index must define at least one column.');

		Blueprint::for(new TestTable('reports'))->index('empty_index');
	}

	public function test_it_rejects_duplicate_column_names_case_insensitively(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Column status is already defined.');

		$definition = Blueprint::for(new TestTable('reports'));

		$definition->string('Status')->nullable();
		$definition->text('status');
	}

	public function test_it_matches_index_column_references_case_insensitively(): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$definition->string('Status');
		$definition->index('status_lookup', 'status');

		$this->assertSame([], $definition->errorsForCreate());
	}

	public function test_it_reports_duplicate_primary_keys(): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$definition->bigIncrements('id');
		$definition->string('status');
		$definition->primary('status');

		$this->assertContains('A table can define only one primary key.', $definition->errorsForCreate());
	}

	public function test_it_reports_duplicate_index_names(): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$definition->bigIncrements('id');
		$definition->string('status');
		$definition->string('type');
		$definition->index('status_lookup', 'status');
		$definition->index('status_lookup', 'type');

		$this->assertContains('Index status_lookup is defined more than once.', $definition->errorsForCreate());
	}

	public function test_it_reports_duplicate_index_names_case_insensitively(): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$definition->string('status');
		$definition->string('type');
		$definition->index('Status_Lookup', 'status');
		$definition->index('status_lookup', 'type');

		$this->assertContains('Index Status_Lookup is defined more than once.', $definition->errorsForCreate());
	}

	public function test_it_reports_every_duplicate_index_name(): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$definition->string('status');
		$definition->string('type');
		$definition->index('status_lookup', 'status');
		$definition->index('status_lookup', 'type');
		$definition->index('type_lookup', 'type');
		$definition->index('TYPE_LOOKUP', 'status');

		$this->assertSame([
			'Index status_lookup is defined more than once.',
			'Index type_lookup is defined more than once.',
		], $definition->errorsForCreate());
	}

	public function test_it_rejects_primary_as_a_secondary_index_name(): void {
		$definition = Blueprint::for(new TestTable('reports'));

		$definition->bigIncrements('id');
		$definition->string('status');
		$definition->index('PRIMARY', 'status');

		$this->assertContains('The PRIMARY index name is reserved for the primary key.', $definition->errorsForCreate());
	}
}
