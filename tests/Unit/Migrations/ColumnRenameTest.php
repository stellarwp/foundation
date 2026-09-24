<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDB1052Platform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StellarWP\Foundation\Migrations\Schema\RenamePlanner;
use StellarWP\Foundation\Migrations\Schema\Renames\ChangeColumnRename;
use StellarWP\Foundation\Migrations\Schema\Renames\NativeColumnRename;
use StellarWP\Foundation\Migrations\Schema\Renames\PlatformColumnRename;
use StellarWP\Foundation\Migrations\Schema\SchemaState;
use StellarWP\Foundation\Migrations\Schema\ValueObjects\Rename;

/**
 * Choose compatible rename SQL from Doctrine's detected platform, without eager connections.
 */
final class ColumnRenameTest extends TestCase
{
	/**
	 * @return iterable<string, array{AbstractPlatform, string}>
	 */
	public static function platforms(): iterable {
		yield 'MySQL 5.7' => [
			new MySQLPlatform(),
			'CHANGE `old` `new`',
		];

		yield 'MySQL 8.0' => [
			new MySQL80Platform(),
			'RENAME COLUMN `old` TO `new`',
		];

		yield 'MariaDB 10.4' => [
			new MariaDBPlatform(),
			'CHANGE `old` `new`',
		];

		yield 'MariaDB 10.5.2' => [
			new MariaDB1052Platform(),
			'RENAME COLUMN `old` TO `new`',
		];
	}

	/**
	 * Use the SQL supported by the server Doctrine selected.
	 *
	 * @dataProvider platforms
	 */
	#[DataProvider('platforms')]
	public function test_platform_selects_compatible_sql(AbstractPlatform $platform, string $expected): void {
		$db = $this->createMock(Connection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$strategy = new PlatformColumnRename($db, new NativeColumnRename($db), new ChangeColumnRename($db));
		$table    = Table::editor()->setUnquotedName('reports')->addColumn(
			Column::editor()->setUnquotedName('old')->setTypeName(Types::INTEGER)->create(),
		)->create();

		$sql = $strategy->sql($table, 'old', 'new');
		$this->assertCount(1, $sql);
		$this->assertStringContainsString($expected, $sql[0]);
	}

	/**
	 * Preserve multiple platform statements through the strategy and the complete rename plan.
	 */
	public function test_platform_statements_remain_separate_and_ordered(): void {
		$statements = [
			'ALTER TABLE reports CHANGE old new INT NOT NULL',
			"ALTER TABLE reports COMMENT = 'Renamed'",
		];
		$platform = $this->createMock(MySQLPlatform::class);
		$platform->expects($this->once())->method('getAlterTableSQL')->willReturn($statements);
		$db = $this->createMock(Connection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$strategy = new PlatformColumnRename($db, new NativeColumnRename($db), new ChangeColumnRename($db));
		$planner  = new RenamePlanner($db, $strategy);
		$table    = Table::editor()->setUnquotedName('reports')->addColumn(
			Column::editor()->setUnquotedName('old')->setTypeName(Types::INTEGER)->create(),
		)->create();
		$before = new SchemaState(new Schema([
			$table,
		]));
		$actual = clone $before;
		$after  = clone $before;
		$after->rename(new Rename('old', 'new', 'reports'));

		$this->assertSame($statements, $planner->plan($before, $actual, $after, '1'));
	}

	/**
	 * Resolving strategies must not initialize the WordPress database session.
	 */
	public function test_construction_does_not_access_the_database(): void {
		$db = $this->createMock(Connection::class);
		$db->expects($this->never())->method('getDatabasePlatform');
		$strategy = new PlatformColumnRename($db, new NativeColumnRename($db), new ChangeColumnRename($db));
		$this->assertInstanceOf(PlatformColumnRename::class, $strategy);
	}
}
