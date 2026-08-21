<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Schema;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Schema\Reconciler;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchemaExecutor;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\SchemaReconciliationTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestTable;
use StellarWP\Foundation\Tests\TestCase;

final class ReconcilerTest extends TestCase
{
	public function test_it_builds_table_definitions_for_the_schema_executor(): void {
		$database               = new FakeDatabase();
		$database->rowResults[] = ['Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'];
		$executor               = new RecordingSchemaExecutor();
		$reconciler             = new Reconciler($database, $executor);

		$reconciler->reconcile(new TestTable('example', 'wp_example'));

		$this->assertStringContainsString('CREATE TABLE `wp_example`', $executor->statements[0]);
		$this->assertSame("SHOW FULL COLUMNS FROM `wp_example` WHERE Field = 'id'", $database->rowQueries[0]);
	}

	public function test_it_accepts_matching_column_defaults_and_nullability(): void {
		$database             = new FakeDatabase();
		$database->rowResults = [
			['Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'],
			['Null' => 'NO', 'Default' => '5', 'Extra' => ''],
			['Null' => 'YES', 'Default' => null, 'Extra' => ''],
			['Null' => 'NO', 'Default' => '', 'Extra' => ''],
			['Null' => 'NO', 'Default' => '1.25', 'Extra' => ''],
			['Null' => 'NO', 'Default' => "b'1'", 'Extra' => ''],
		];
		$reconciler = new Reconciler($database, new RecordingSchemaExecutor());

		$reconciler->reconcile(new SchemaReconciliationTable('wp_example', 5, true));

		$this->assertSame([], $database->executed);
	}

	public function test_it_fails_when_column_defaults_and_nullability_remain_unapplied(): void {
		$database             = new FakeDatabase();
		$database->rowResults = [
			['Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'],
			['Null' => 'NO', 'Default' => 'not-an-integer', 'Extra' => ''],
			['Null' => 'NO', 'Default' => null, 'Extra' => ''],
			['Null' => 'NO', 'Default' => '', 'Extra' => ''],
			['Null' => 'NO', 'Default' => '1.25', 'Extra' => ''],
			['Null' => 'NO', 'Default' => "b'1'", 'Extra' => ''],
		];
		$reconciler = new Reconciler($database, new RecordingSchemaExecutor());

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('column attempts expected DEFAULT 5, found DEFAULT not-an-integer; column completed_at expected NULL, found NOT NULL');

		$reconciler->reconcile(new SchemaReconciliationTable('wp_example', 5, true));
	}

	public function test_it_does_not_treat_a_missing_default_as_an_empty_string(): void {
		$database             = new FakeDatabase();
		$database->rowResults = [
			['Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'],
			['Null' => 'NO', 'Default' => '5', 'Extra' => ''],
			['Null' => 'NO', 'Default' => null, 'Extra' => ''],
			['Null' => 'NO', 'Default' => null, 'Extra' => ''],
			['Null' => 'NO', 'Default' => '1.25', 'Extra' => ''],
			['Null' => 'NO', 'Default' => "b'1'", 'Extra' => ''],
		];
		$reconciler = new Reconciler($database, new RecordingSchemaExecutor());

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage("column label expected DEFAULT '', found DEFAULT NULL");

		$reconciler->reconcile(new SchemaReconciliationTable('wp_example', 5, false));
	}

	public function test_it_rejects_unapplied_column_extra_attributes(): void {
		$database               = new FakeDatabase();
		$database->rowResults[] = ['Null' => 'NO', 'Default' => null, 'Extra' => ''];
		$reconciler             = new Reconciler($database, new RecordingSchemaExecutor());

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('column id expected extra auto_increment, found none');

		$reconciler->reconcile(new TestTable('example', 'wp_example'));
	}

	public function test_it_rejects_missing_column_metadata(): void {
		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('could not inspect wp_example.id');

		(new Reconciler(new FakeDatabase(), new RecordingSchemaExecutor()))
			->reconcile(new TestTable('example', 'wp_example'));
	}

	public function test_it_rejects_invalid_column_metadata(): void {
		$database               = new FakeDatabase();
		$database->rowResults[] = ['Null' => 'MAYBE', 'Default' => null, 'Extra' => ''];

		$this->expectException(DatabaseException::class);
		$this->expectExceptionMessage('returned invalid column metadata for wp_example.id');

		(new Reconciler($database, new RecordingSchemaExecutor()))
			->reconcile(new TestTable('example', 'wp_example'));
	}
}
