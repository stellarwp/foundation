<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Query\Upsert;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use LogicException;
use PHPUnit\Framework\TestCase;
use StellarWP\Foundation\Database\Query\IdentifierQuoter;
use StellarWP\Foundation\Database\Query\Upsert\AliasBuilder;
use StellarWP\Foundation\Database\Query\Upsert\ServerBuilder;
use StellarWP\Foundation\Database\Query\Upsert\ValueBuilder;

final class BuilderTest extends TestCase
{
	public function test_mysql_alias_strategy_and_values_strategy(): void {
		$columns = [
			'name',
			'updated_at',
		];
		$this->assertSame(' AS `foundation_incoming` ON DUPLICATE KEY UPDATE `name` = `foundation_incoming`.`name`, `updated_at` = `foundation_incoming`.`updated_at`', (new AliasBuilder(new IdentifierQuoter()))->build($columns, 'wp_entries'));
		$this->assertSame(' ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `updated_at` = VALUES(`updated_at`)', (new ValueBuilder(new IdentifierQuoter()))->build($columns, 'wp_entries'));
	}

	public function test_mysql_incoming_alias_differs_from_target_table(): void {
		$this->assertSame(' AS `_foundation_incoming` ON DUPLICATE KEY UPDATE `name` = `_foundation_incoming`.`name`', (new AliasBuilder(new IdentifierQuoter()))->build([
			'name',
		], 'foundation_incoming'));
	}

	public function test_constructing_builder_does_not_connect(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->never())->method('getDatabasePlatform');
		$connection->expects($this->never())->method('getServerVersion');
		$this->assertInstanceOf(ServerBuilder::class, $this->builder($connection));
	}

	public function test_mysql_8019_selects_aliases_and_discovers_once(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->once())->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
		$connection->expects($this->once())->method('getServerVersion')->willReturn('8.0.19-commercial');
		$builder = $this->builder($connection);
		$columns = [
			'name',
		];
		$this->assertSame((new AliasBuilder(new IdentifierQuoter()))->build($columns, 'wp_entries'), $builder->build($columns, 'wp_entries'));
		$this->assertStringContainsString('`other`', $builder->build([
			'other',
		], 'wp_entries'));
	}

	public function test_old_mysql_uses_values(): void {
		$connection = $this->createMock(Connection::class);
		$connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
		$connection->method('getServerVersion')->willReturn('8.0.18');
		$columns = [
			'name',
		];
		$this->assertSame((new ValueBuilder(new IdentifierQuoter()))->build($columns, 'wp_entries'), $this->builder($connection)->build($columns, 'wp_entries'));
	}

	public function test_mariadb_uses_values_without_inspecting_version(): void {
		$connection = $this->createMock(Connection::class);
		$connection->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
		$connection->expects($this->never())->method('getServerVersion');
		$columns = [
			'name',
		];
		$this->assertSame((new ValueBuilder(new IdentifierQuoter()))->build($columns, 'wp_entries'), $this->builder($connection)->build($columns, 'wp_entries'));
	}

	public function test_other_platforms_are_rejected(): void {
		$connection = $this->createMock(Connection::class);
		$connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
		$this->expectException(LogicException::class);
		$this->builder($connection)->build([
			'name',
		], 'wp_entries');
	}

	/**
	 * Compose lazy strategy selection with the test connection.
	 */
	private function builder(Connection $connection): ServerBuilder {
		$quoter = new IdentifierQuoter();

		return new ServerBuilder(
			$connection,
			new AliasBuilder($quoter),
			new ValueBuilder($quoter),
		);
	}
}
