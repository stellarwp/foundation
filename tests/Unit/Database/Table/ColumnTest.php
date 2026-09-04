<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\ValueObjects\ColumnComment;
use StellarWP\Foundation\Tests\TestCase;

final class ColumnTest extends TestCase
{
	public function test_it_renders_column_sql_with_common_options(): void {
		$column = new Column(
			name: 'queue_id',
			type: 'bigint',
			length: 20,
			unsigned: true,
			autoIncrement: true
		);

		$this->assertSame('`queue_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT', $column->sql());
	}

	public function test_it_quotes_column_names_and_escapes_embedded_backticks(): void {
		$this->assertSame(
			'`report``status` varchar(20) NOT NULL',
			(new Column('report`status', 'varchar', 20))->sql()
		);
	}

	public function test_it_renders_nullable_and_default_values(): void {
		$this->assertSame(
			"`status` varchar(20) NULL DEFAULT 'pending'",
			(new Column('status', 'varchar', 20, nullable: true, default: 'pending', hasDefault: true))->sql()
		);

		$this->assertSame(
			'`attempts` int(10) unsigned NOT NULL DEFAULT 0',
			(new Column('attempts', 'int', 10, unsigned: true, default: 0, hasDefault: true))->sql()
		);
	}

	public function test_it_renders_explicit_null_and_boolean_defaults(): void {
		$this->assertSame(
			'`completed_at` datetime NULL DEFAULT NULL',
			(new Column('completed_at', 'datetime', nullable: true, hasDefault: true))->sql()
		);

		$this->assertSame(
			'`enabled` tinyint(1) unsigned NOT NULL DEFAULT 1',
			(new Column('enabled', 'tinyint', 1, unsigned: true, default: true, hasDefault: true))->sql()
		);
	}

	public function test_it_renders_typed_column_comments(): void {
		$column = new Column(
			'id',
			'bigint',
			20,
			autoIncrement: true,
			comment: new ColumnComment("Customer's identifier; internal metadata")
		);

		$this->assertSame(
			"`id` bigint(20) NOT NULL AUTO_INCREMENT COMMENT 'Customer''s identifier; internal metadata'",
			$column->sql()
		);
	}

	public function test_it_canonicalizes_common_custom_type_spellings(): void {
		$this->assertSame('double', (new Column('measurement', 'DOUBLE PRECISION'))->typeSql());
		$this->assertSame('decimal(10,2)', (new Column('amount', 'decimal(10, 2)'))->typeSql());
		$this->assertSame("enum('a, b','c')", (new Column('state', "enum('a, b','c')"))->typeSql());
	}

	public function test_it_reports_invalid_final_column_states(): void {
		$this->assertSame(
			['Column id cannot be nullable because it uses AUTO_INCREMENT.'],
			(new Column('id', 'bigint', 20, nullable: true, autoIncrement: true))->validationErrors()
		);

		$this->assertSame(
			['Column completed_at cannot use DEFAULT NULL unless it is nullable.'],
			(new Column('completed_at', 'datetime', hasDefault: true))->validationErrors()
		);
	}

	public function test_it_rejects_auto_increment_on_non_integer_columns(): void {
		$this->assertSame(
			['Column slug must use an integer type because it uses AUTO_INCREMENT.'],
			(new Column('slug', 'varchar', 191, autoIncrement: true))->validationErrors()
		);
	}

	public function test_it_rejects_explicit_defaults_on_auto_increment_columns(): void {
		$this->assertSame(
			['Column id cannot define a default because it uses AUTO_INCREMENT.'],
			(new Column('id', 'bigint', 20, default: 10, hasDefault: true, autoIncrement: true))->validationErrors()
		);
	}

	public function test_it_escapes_string_defaults_as_sql_literals(): void {
		$column = new Column('label', 'varchar', 50, default: "customer's \\ path", hasDefault: true);

		$this->assertSame(
			"`label` varchar(50) NOT NULL DEFAULT X'637573746f6d65722773205c2070617468'",
			$column->sql()
		);
		$this->assertSame("X'637573746f6d65722773205c2070617468'", $column->defaultSql());
		$this->assertNull((new Column('label', 'varchar', 50))->defaultSql());
	}

	public function test_it_rejects_a_default_without_an_explicit_default_state(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('must set hasDefault');

		new Column('status', 'varchar', 20, default: 'pending');
	}
}
