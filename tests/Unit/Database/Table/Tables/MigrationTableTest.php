<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Table\Tables;

use StellarWP\Foundation\Database\Schema as DatabaseSchema;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\FakeDatabase;
use StellarWP\Foundation\Tests\TestCase;

final class MigrationTableTest extends TestCase
{
	public function test_it_creates_the_migration_table(): void {
		$database   = new FakeDatabase();
		$statements = [];
		$schema     = new DatabaseSchema($database, static function (string $sql, bool $execute) use (&$statements): array {
			if ($execute) {
				$statements[] = $sql;
			}

			return [];
		});
		$table = new MigrationTable('network_foundation_migrations');

		$schema->createOrUpdate($table);

		$this->assertSame(MigrationTable::ID, $table->id());
		$this->assertSame('network_foundation_migrations', $table->name());
		$this->assertStringContainsString('CREATE TABLE `network_foundation_migrations`', $statements[0]);
		$this->assertStringContainsString('`migration` varbinary(191)', $statements[0]);
		$this->assertStringContainsString('UNIQUE KEY `migration`', $statements[0]);
	}

	public function test_it_drops_the_migration_table(): void {
		$database = new FakeDatabase();
		$schema   = new DatabaseSchema($database, static fn (string $sql, bool $execute): array => []);
		$table    = new MigrationTable('network_foundation_migrations');

		$schema->drop($table);

		$this->assertSame('DROP TABLE IF EXISTS `network_foundation_migrations`', $database->executed[0]);
	}
}
