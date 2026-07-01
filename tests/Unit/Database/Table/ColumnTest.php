<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table;

use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Tests\TestCase;

final class ColumnTest extends TestCase
{
	public function test_it_renders_column_sql_with_common_options(): void {
		$column = new Column(
			name: 'queue_id',
			type: 'bigint',
			length: 20,
			unsigned: true,
			extra: 'AUTO_INCREMENT'
		);

		$this->assertSame('`queue_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT', $column->sql());
	}

	public function test_it_renders_nullable_and_default_values(): void {
		$this->assertSame(
			"`status` varchar(20) NULL DEFAULT 'pending'",
			(new Column('status', 'varchar', 20, nullable: true, default: 'pending'))->sql()
		);

		$this->assertSame(
			'`attempts` int(10) unsigned NOT NULL DEFAULT 0',
			(new Column('attempts', 'int', 10, unsigned: true, default: 0))->sql()
		);
	}

	public function test_it_renders_explicit_null_and_boolean_defaults(): void {
		$this->assertSame(
			'`completed_at` datetime NULL DEFAULT NULL',
			(new Column('completed_at', 'datetime'))->nullable()->default(null)->sql()
		);

		$this->assertSame(
			'`enabled` tinyint(1) unsigned NOT NULL DEFAULT 1',
			(new Column('enabled', 'tinyint', 1))->unsigned()->default(true)->sql()
		);
	}

	public function test_it_returns_modified_column_copies(): void {
		$column = new Column('id', 'bigint', 20);

		$this->assertSame('`id` bigint(20) NOT NULL', $column->sql());
		$this->assertSame('`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT', $column->unsigned()->autoIncrement()->sql());
	}

	public function test_auto_increment_is_idempotent(): void {
		$this->assertSame(
			'`id` bigint(20) NOT NULL AUTO_INCREMENT',
			(new Column('id', 'bigint', 20))->autoIncrement()->autoIncrement()->sql()
		);
	}
}
