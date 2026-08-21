<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table\Tables;

use StellarWP\Foundation\Database\Schema as DatabaseSchema;
use StellarWP\Foundation\Database\Schema\Reconciler;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchemaExecutor;
use StellarWP\Foundation\Tests\TestCase;

final class MigrationTableTest extends TestCase
{
	public function test_it_creates_the_migration_table(): void {
		$database             = new FakeDatabase();
		$database->rowResults = [
			['Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'],
			...array_fill(0, 3, ['Null' => 'NO', 'Default' => null, 'Extra' => '']),
		];
		$executor = new RecordingSchemaExecutor();
		$schema   = new DatabaseSchema($database, new Reconciler($database, $executor));
		$table    = new MigrationTable('network_foundation_migrations');

		$schema->createOrUpdate($table);

		$this->assertSame(MigrationTable::ID, $table->id());
		$this->assertSame('network_foundation_migrations', $table->name());
		$this->assertStringContainsString('CREATE TABLE `network_foundation_migrations`', $executor->statements[0]);
		$this->assertStringContainsString('`migration` varbinary(191)', $executor->statements[0]);
		$this->assertStringContainsString('UNIQUE KEY `migration`', $executor->statements[0]);
	}

	public function test_it_drops_the_migration_table(): void {
		$database = new FakeDatabase();
		$schema   = new DatabaseSchema($database, new Reconciler($database, new RecordingSchemaExecutor()));
		$table    = new MigrationTable('network_foundation_migrations');

		$schema->drop($table);

		$this->assertSame('DROP TABLE IF EXISTS `network_foundation_migrations`', $database->executed[0]);
	}
}
