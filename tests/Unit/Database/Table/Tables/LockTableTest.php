<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table\Tables;

use StellarWP\Foundation\Database\Schema as DatabaseSchema;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\TestCase;

final class LockTableTest extends TestCase
{
	public function test_it_creates_the_lock_table(): void {
		$database   = new FakeDatabase();
		$statements = [];
		$schema     = new DatabaseSchema($database, static function (string $sql) use (&$statements): void {
			$statements[] = $sql;
		});
		$table = new LockTable($database, 'wp_nexcess_foundation_locks');

		$schema->createOrUpdate($table);

		$this->assertSame(LockTable::ID, $table->id());
		$this->assertSame('wp_nexcess_foundation_locks', $table->name());
		$this->assertStringContainsString('CREATE TABLE `wp_nexcess_foundation_locks`', $statements[0]);
		$this->assertStringContainsString('PRIMARY KEY  (`name`)', $statements[0]);
		$this->assertStringContainsString('KEY `expires_at`', $statements[0]);
	}

	public function test_it_drops_the_lock_table(): void {
		$database = new FakeDatabase();
		$schema   = new DatabaseSchema($database, static fn (string $sql): array => []);
		$table    = new LockTable($database, 'wp_nexcess_foundation_locks');

		$schema->drop($table);

		$this->assertSame('DROP TABLE IF EXISTS `wp_nexcess_foundation_locks`', $database->executed[0]);
	}
}
