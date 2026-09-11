<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table\Tables;

use StellarWP\Foundation\Database\Migration\StoreSchema;
use StellarWP\Foundation\Database\Schema as DatabaseSchema;
use StellarWP\Foundation\Database\Schema\Editor;
use StellarWP\Foundation\Database\Schema\Reconciler;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchemaExecutor;
use StellarWP\Foundation\Tests\TestCase;

final class MigrationTableTest extends TestCase
{
	public function test_it_creates_the_migration_table(): void {
		$database             = new FakeDatabase();
		$database->rowResults = [
			null,
			null,
			['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'],
			['Type' => 'varbinary(191)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
			['Type' => 'int(10) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
			['Type' => 'datetime', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
		];
		$database->rowsResults[] = [
			['Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'id', 'Sub_part' => null, 'Index_type' => 'BTREE', 'Collation' => 'A'],
			['Key_name' => 'migration', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'migration', 'Sub_part' => null, 'Index_type' => 'BTREE', 'Collation' => 'A'],
			['Key_name' => 'batch', 'Non_unique' => 1, 'Seq_in_index' => 1, 'Column_name' => 'batch', 'Sub_part' => null, 'Index_type' => 'BTREE', 'Collation' => 'A'],
		];
		$executor   = new RecordingSchemaExecutor();
		$reconciler = new Reconciler($database, $executor);
		$schema     = new DatabaseSchema($database, $reconciler, new Editor($database, $reconciler));
		$table      = new MigrationTable('network_foundation_migrations', $database);

		(new StoreSchema(
			$schema,
			$reconciler,
			$table,
			new LockTable('network_foundation_locks', $database)
		))->initializeLedger();

		$this->assertSame('wp_network_foundation_migrations', $table->name());
		$this->assertStringContainsString('CREATE TABLE `wp_network_foundation_migrations`', $executor->statements[0]);
		$this->assertStringContainsString('`migration` varbinary(191)', $executor->statements[0]);
		$this->assertStringContainsString('UNIQUE KEY `migration`', $executor->statements[0]);
	}

	public function test_it_drops_the_migration_table(): void {
		$database   = new FakeDatabase();
		$reconciler = new Reconciler($database, new RecordingSchemaExecutor());
		$schema     = new DatabaseSchema($database, $reconciler, new Editor($database, $reconciler));
		$table      = new MigrationTable('network_foundation_migrations', $database);

		$schema->drop($table);

		$this->assertSame('DROP TABLE IF EXISTS `wp_network_foundation_migrations`', $database->executed[0]);
	}

	public function test_it_resolves_its_name_from_the_active_scope(): void {
		$database = new FakeDatabase();
		$table    = new MigrationTable('foundation_migrations', $database);

		$database->prefix = 'wp_2_';

		$this->assertSame('wp_2_foundation_migrations', $table->name());

		$database->prefix = 'wp_3_';

		$this->assertSame('wp_3_foundation_migrations', $table->name());
	}
}
