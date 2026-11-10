<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table;

use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\ColumnDefinition;
use StellarWP\Foundation\Database\Table\ValueObjects\ColumnComment;
use StellarWP\Foundation\Database\Table\ValueObjects\CurrentTimestamp;
use StellarWP\Foundation\Tests\TestCase;

final class ColumnDefinitionTest extends TestCase
{
	public function test_it_builds_a_column_from_fluent_modifiers(): void {
		$column = (new ColumnDefinition(new Column('id', 'bigint', 20)))
			->unsigned()
			->nullable()
			->notNull()
			->autoIncrement()
			->comment('Internal identifier')
			->toColumn();

		$this->assertSame(
			"`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Internal identifier'",
			$column->sql()
		);
	}

	public function test_an_explicit_null_default_does_not_change_nullability(): void {
		$column = (new ColumnDefinition(new Column('completed_at', 'datetime')))
			->default(null)
			->toColumn();

		$this->assertSame('`completed_at` datetime NOT NULL DEFAULT NULL', $column->sql());
	}

	public function test_modifiers_are_idempotent(): void {
		$column = (new ColumnDefinition(new Column('id', 'bigint', 20)))
			->autoIncrement()
			->autoIncrement()
			->toColumn();

		$this->assertSame('`id` bigint(20) NOT NULL AUTO_INCREMENT', $column->sql());
	}

	public function test_created_columns_are_immutable_snapshots(): void {
		$definition = new ColumnDefinition(new Column('updated_at', 'datetime', 6));
		$original   = $definition->toColumn();
		$current    = $definition->useCurrent()->toColumn();
		$automatic  = $definition->useCurrentOnUpdate()->toColumn();
		$nullable   = $definition->nullable()->default(null)->toColumn();

		$this->assertSame('`updated_at` datetime(6) NOT NULL', $original->sql());
		$this->assertSame('`updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)', $current->sql());
		$this->assertSame(
			'`updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)',
			$automatic->sql()
		);
		$this->assertSame('`updated_at` datetime(6) NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(6)', $nullable->sql());
	}

	public function test_the_last_default_modifier_wins_without_changing_automatic_updates(): void {
		$definition = new ColumnDefinition(new Column('updated_at', 'timestamp', 3));
		$definition->nullable()->useCurrentOnUpdate()->default(null)->useCurrent();

		$this->assertSame(
			'`updated_at` timestamp(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)',
			$definition->toColumn()->sql()
		);

		$definition->default('CURRENT_TIMESTAMP');

		$this->assertSame(
			"`updated_at` timestamp(3) NULL DEFAULT 'CURRENT_TIMESTAMP' ON UPDATE CURRENT_TIMESTAMP(3)",
			$definition->toColumn()->sql()
		);
	}

	public function test_automatic_updates_do_not_add_a_default_or_change_nullability(): void {
		$definition = new ColumnDefinition(new Column('updated_at', 'datetime'));
		$definition->useCurrentOnUpdate()->useCurrentOnUpdate();

		$this->assertSame('`updated_at` datetime NOT NULL ON UPDATE CURRENT_TIMESTAMP', $definition->toColumn()->sql());
	}

	public function test_it_preserves_automatic_attributes_from_a_direct_column_seed(): void {
		$definition = new ColumnDefinition(new Column(
			'updated_at',
			'TIMESTAMP(6)',
			null,
			false,
			true,
			new CurrentTimestamp(),
			true,
			false,
			new ColumnComment('Database managed'),
			true
		));

		$this->assertSame(
			"`updated_at` TIMESTAMP(6) NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) COMMENT 'Database managed'",
			$definition->toColumn()->sql()
		);
	}
}
