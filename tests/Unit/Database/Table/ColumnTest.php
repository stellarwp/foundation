<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Database\Table\Column;
use StellarWP\Foundation\Database\Table\ValueObjects\ColumnComment;
use StellarWP\Foundation\Database\Table\ValueObjects\CurrentTimestamp;
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

	public function test_it_preserves_existing_positional_constructor_arguments(): void {
		$column = new Column('status', 'varchar', 20, false, true, 'pending', true, false, new ColumnComment('Workflow'));

		$this->assertSame("`status` varchar(20) NULL DEFAULT 'pending' COMMENT 'Workflow'", $column->sql());
	}

	/**
	 * @dataProvider temporalTypes
	 */
	#[DataProvider('temporalTypes')]
	public function test_it_uses_column_precision_for_both_current_timestamp_expressions(
		string $type,
		?int $length,
		string $expectedType,
		string $expectedExpression
	): void {
		$column = new Column(
			'updated_at',
			$type,
			$length,
			default: new CurrentTimestamp(),
			hasDefault: true,
			onUpdateCurrentTimestamp: true
		);

		$this->assertSame(
			"`updated_at` {$expectedType} NOT NULL DEFAULT {$expectedExpression} ON UPDATE {$expectedExpression}",
			$column->sql()
		);
		$this->assertSame([], $column->validationErrors());
	}

	/**
	 * @return array<string, array{string, ?int, string, string}>
	 */
	public static function temporalTypes(): array {
		return [
			'datetime seconds'        => ['datetime', null, 'datetime', 'CURRENT_TIMESTAMP'],
			'timestamp explicit zero' => ['timestamp', 0, 'timestamp(0)', 'CURRENT_TIMESTAMP'],
			'minimum fraction'        => ['datetime', 1, 'datetime(1)', 'CURRENT_TIMESTAMP(1)'],
			'maximum fraction'        => ['timestamp', 6, 'timestamp(6)', 'CURRENT_TIMESTAMP(6)'],
			'embedded precision'      => ['datetime(6)', null, 'datetime(6)', 'CURRENT_TIMESTAMP(6)'],
			'embedded zero'           => ['timestamp(0)', null, 'timestamp(0)', 'CURRENT_TIMESTAMP'],
			'case and whitespace'     => [' TIMESTAMP ( 3 ) ', null, 'TIMESTAMP ( 3 )', 'CURRENT_TIMESTAMP(3)'],
		];
	}

	public function test_current_timestamp_text_remains_a_literal_default(): void {
		$column = new Column('created_at', 'datetime', default: 'CURRENT_TIMESTAMP', hasDefault: true);

		$this->assertSame("`created_at` datetime NOT NULL DEFAULT 'CURRENT_TIMESTAMP'", $column->sql());
		$this->assertSame("'CURRENT_TIMESTAMP'", $column->defaultSql());
		$this->assertNull($column->onUpdateSql());
	}

	/**
	 * @dataProvider invalidTemporalTypes
	 */
	#[DataProvider('invalidTemporalTypes')]
	public function test_it_rejects_invalid_temporal_declarations_before_rendering(
		string $type,
		?int $length,
		bool $useCurrent
	): void {
		$column = new Column(
			'managed_at',
			$type,
			$length,
			default: $useCurrent ? new CurrentTimestamp() : null,
			hasDefault: $useCurrent,
			onUpdateCurrentTimestamp: ! $useCurrent
		);

		$this->assertCount(1, $column->validationErrors());
		$this->expectException(InvalidArgumentException::class);

		$column->sql();
	}

	/**
	 * @return array<string, array{string, ?int, bool}>
	 */
	public static function invalidTemporalTypes(): array {
		return [
			'non-temporal default'        => ['varchar', 20, true],
			'non-temporal update'         => ['int', 10, false],
			'date is not datetime'        => ['date', null, true],
			'negative precision'          => ['datetime', -1, true],
			'excess precision'            => ['timestamp', 7, false],
			'embedded negative precision' => ['timestamp(-1)', null, false],
			'embedded excess precision'   => ['datetime(7)', null, true],
			'duplicate precision'         => ['datetime(3)', 3, false],
			'fractional precision'        => ['timestamp(1.5)', null, true],
			'trailing type attributes'    => ['datetime unsigned', null, false],
		];
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

	/**
	 * @dataProvider decimalTypes
	 */
	#[DataProvider('decimalTypes')]
	public function test_it_normalizes_decimal_precision_and_scale(string $type, ?int $length, string $expected): void {
		$this->assertSame($expected, (new Column('amount', $type, $length))->typeSql());
	}

	/**
	 * @return array<string, array{string, ?int, string}>
	 */
	public static function decimalTypes(): array {
		return [
			'precision'                  => ['decimal(12)', null, 'decimal(12,0)'],
			'numeric precision'          => ['NUMERIC(12)', null, 'decimal(12,0)'],
			'dec precision'              => ['dec(12)', null, 'decimal(12,0)'],
			'separate precision'         => ['decimal', 12, 'decimal(12,0)'],
			'numeric separate precision' => ['numeric', 12, 'decimal(12,0)'],
			'dec separate precision'     => ['dec', 12, 'decimal(12,0)'],
			'default precision'          => ['decimal', null, 'decimal(10,0)'],
			'numeric default precision'  => ['numeric', null, 'decimal(10,0)'],
			'dec default precision'      => ['dec', null, 'decimal(10,0)'],
			'explicit scale'             => ['decimal(12, 2)', null, 'decimal(12,2)'],
			'numeric explicit scale'     => ['NUMERIC(12, 2)', null, 'decimal(12,2)'],
			'dec explicit scale'         => ['dec(12, 2)', null, 'decimal(12,2)'],
		];
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
