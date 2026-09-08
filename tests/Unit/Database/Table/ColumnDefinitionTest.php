<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table;

use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\ColumnDefinition;
use StellarWP\Foundation\Tests\TestCase;

final class ColumnDefinitionTest extends TestCase
{
	public function test_it_builds_a_column_from_fluent_modifiers(): void {
		$column = (new ColumnDefinition(new Column('id', 'bigint', 20)))
			->unsigned()
			->nullable()
			->notNull()
			->default(10)
			->autoIncrement()
			->comment('Internal identifier')
			->toColumn();

		$this->assertSame('id', $column->name);
		$this->assertSame('bigint', $column->type);
		$this->assertSame(20, $column->length);
		$this->assertTrue($column->unsigned);
		$this->assertFalse($column->nullable);
		$this->assertSame(10, $column->default);
		$this->assertTrue($column->hasDefault);
		$this->assertTrue($column->autoIncrement);
		$this->assertSame('Internal identifier', $column->commentText());
	}

	public function test_an_explicit_null_default_does_not_change_nullability(): void {
		$column = (new ColumnDefinition(new Column('completed_at', 'datetime')))
			->default(null)
			->toColumn();

		$this->assertFalse($column->nullable);
		$this->assertTrue($column->hasDefault);
		$this->assertSame('NULL', $column->defaultSql());
	}

	public function test_modifiers_are_idempotent(): void {
		$column = (new ColumnDefinition(new Column('id', 'bigint', 20)))
			->autoIncrement()
			->autoIncrement()
			->toColumn();

		$this->assertSame('`id` bigint(20) NOT NULL AUTO_INCREMENT', $column->sql());
	}

	public function test_created_columns_are_immutable_snapshots(): void {
		$definition = new ColumnDefinition(new Column('status', 'varchar', 20));
		$original   = $definition->toColumn();

		$definition->nullable()->default('pending');
		$modified = $definition->toColumn();

		$this->assertNotSame($original, $modified);
		$this->assertFalse($original->nullable);
		$this->assertFalse($original->hasDefault);
		$this->assertTrue($modified->nullable);
		$this->assertSame('pending', $modified->default);
		$this->assertTrue($modified->hasDefault);
	}
}
