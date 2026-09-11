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

final class LockTableTest extends TestCase
{
	public function test_it_creates_the_lock_table(): void {
		$database             = new FakeDatabase();
		$database->rowResults = [
			null,
			null,
			['Type' => 'varbinary(191)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
			['Type' => 'varbinary(64)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
			...array_fill(0, 3, ['Type' => 'datetime(6)', 'Null' => 'NO', 'Default' => null, 'Extra' => '']),
		];
		$database->rowsResults[] = [
			['Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'name', 'Sub_part' => null, 'Index_type' => 'BTREE', 'Collation' => 'A'],
			['Key_name' => 'expires_at', 'Non_unique' => 1, 'Seq_in_index' => 1, 'Column_name' => 'expires_at', 'Sub_part' => null, 'Index_type' => 'BTREE', 'Collation' => 'A'],
		];
		$executor   = new RecordingSchemaExecutor();
		$reconciler = new Reconciler($database, $executor);
		$schema     = new DatabaseSchema($database, $reconciler, new Editor($database, $reconciler));
		$table      = new LockTable('network_foundation_locks', $database);

		(new StoreSchema(
			$schema,
			$reconciler,
			new MigrationTable('network_foundation_migrations', $database),
			$table
		))->initializeLock();

		$this->assertSame('wp_network_foundation_locks', $table->name());
		$this->assertStringContainsString('CREATE TABLE `wp_network_foundation_locks`', $executor->statements[0]);
		$this->assertStringContainsString('`name` varbinary(191)', $executor->statements[0]);
		$this->assertStringContainsString('`owner` varbinary(64)', $executor->statements[0]);
		$this->assertStringContainsString('`expires_at` datetime(6)', $executor->statements[0]);
		$this->assertStringContainsString('`created_at` datetime(6)', $executor->statements[0]);
		$this->assertStringContainsString('`updated_at` datetime(6)', $executor->statements[0]);
		$this->assertStringContainsString('PRIMARY KEY  (`name`)', $executor->statements[0]);
		$this->assertStringContainsString('KEY `expires_at`', $executor->statements[0]);
	}

	public function test_it_drops_the_lock_table(): void {
		$database   = new FakeDatabase();
		$reconciler = new Reconciler($database, new RecordingSchemaExecutor());
		$schema     = new DatabaseSchema($database, $reconciler, new Editor($database, $reconciler));
		$table      = new LockTable('network_foundation_locks', $database);

		$schema->drop($table);

		$this->assertSame('DROP TABLE IF EXISTS `wp_network_foundation_locks`', $database->executed[0]);
	}

	public function test_it_resolves_its_name_from_the_active_scope(): void {
		$database = new FakeDatabase();
		$table    = new LockTable('foundation_locks', $database);

		$database->prefix = 'wp_2_';

		$this->assertSame('wp_2_foundation_locks', $table->name());

		$database->prefix = 'wp_3_';

		$this->assertSame('wp_3_foundation_locks', $table->name());
	}
}
