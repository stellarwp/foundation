<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table\Tables;

use StellarWP\Foundation\Database\Schema as DatabaseSchema;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\RecordingSchemaExecutor;
use StellarWP\Foundation\Tests\TestCase;

final class LockTableTest extends TestCase
{
	public function test_it_creates_the_lock_table(): void {
		$database = new FakeDatabase();
		$executor = new RecordingSchemaExecutor();
		$schema   = new DatabaseSchema($database, $executor);
		$table    = new LockTable('network_foundation_locks');

		$schema->createOrUpdate($table);

		$this->assertSame(LockTable::ID, $table->id());
		$this->assertSame('network_foundation_locks', $table->name());
		$this->assertStringContainsString('CREATE TABLE `network_foundation_locks`', $executor->statements[0]);
		$this->assertStringContainsString('`name` varbinary(191)', $executor->statements[0]);
		$this->assertStringContainsString('`owner` varbinary(64)', $executor->statements[0]);
		$this->assertStringContainsString('`expires_at` datetime(6)', $executor->statements[0]);
		$this->assertStringContainsString('`created_at` datetime(6)', $executor->statements[0]);
		$this->assertStringContainsString('`updated_at` datetime(6)', $executor->statements[0]);
		$this->assertStringContainsString('PRIMARY KEY  (`name`)', $executor->statements[0]);
		$this->assertStringContainsString('KEY `expires_at`', $executor->statements[0]);
	}

	public function test_it_drops_the_lock_table(): void {
		$database = new FakeDatabase();
		$schema   = new DatabaseSchema($database, new RecordingSchemaExecutor());
		$table    = new LockTable('network_foundation_locks');

		$schema->drop($table);

		$this->assertSame('DROP TABLE IF EXISTS `network_foundation_locks`', $database->executed[0]);
	}
}
