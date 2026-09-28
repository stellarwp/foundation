<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Migrations;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Migrations\MigrationsProvider;
use StellarWP\Foundation\Migrations\Migrator;
use StellarWP\Foundation\Migrations\Schema\Renames\ChangeColumnRename;
use StellarWP\Foundation\Migrations\Schema\Renames\Contracts\ColumnRename;
use StellarWP\Foundation\Migrations\Schema\Renames\PlatformColumnRename;
use StellarWP\Foundation\Migrations\ValueObjects\MigrationRegistration;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\Columns\ChangeCommonColumns;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\Columns\CreateCommonColumns;
use StellarWP\Foundation\Tests\Support\Fixtures\Migrations\Columns\RenameCommonColumns;

/**
 * Common column declarations round-trip through the database and migration execution.
 */
final class CommonColumnTest extends DatabaseTestCase
{
	private string $entries;
	private string $history;

	protected function configuration(): array {
		return [
			'migrations' => [
				'table' => $this->suffix . '_history',
			],
		];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->container->register(MigrationsProvider::class);
		$this->entries = $this->privateTable($this->suffix . '_entries');
		$this->history = $this->privateTable($this->suffix . '_history');
		$name          = $this->suffix . '_entries';
		$this->container->mergeArrayVar(MigrationsProvider::MIGRATIONS, [
			new MigrationRegistration('1', new CreateCommonColumns($name)),
			new MigrationRegistration('2', new ChangeCommonColumns($name)),
			new MigrationRegistration('3', new RenameCommonColumns($name)),
		]);
	}

	/**
	 * Completed migrations are not repeated and retain their column definitions and values.
	 */
	public function test_creation_preserves_types_defaults_and_data(): void {
		$migrator = $this->container->get(Migrator::class);
		$migrator->migrate(Migrator::NONE);
		$migrator->migrate('1');
		$this->insertEntry();
		$steps = $migrator->migrate('1');
		$this->assertSame([], $steps);
		$this->assertInitialColumns();
		$this->assertEntry();
		$this->assertSame(1, (int) $this->observer->fetchOne("SELECT COUNT(*) FROM {$this->history} WHERE version = '1'"));

		$this->observer->insert($this->entries, [
			'content' => null,
		]);
		$this->assertSame('2026-01-01', $this->observer->fetchOne("SELECT starts_on FROM {$this->entries} WHERE id = 2"));
		$this->assertSame('09:00:00', $this->observer->fetchOne("SELECT opens_at FROM {$this->entries} WHERE id = 2"));
		$this->assertNull($this->observer->fetchOne("SELECT metadata FROM {$this->entries} WHERE id = 2"));
		$migrator->rollback();
		$this->assertFalse($this->observer->createSchemaManager()->tablesExist([
			$this->source->prefix . $this->suffix . '_entries',
		]));
	}

	/**
	 * Fixed binary values are padded; variable binary values retain every supplied byte.
	 */
	public function test_binary_declarations_match_sql_types_and_storage_behavior(): void {
		$migrator = $this->container->get(Migrator::class);
		$preview  = implode("\n", $migrator->preview('1')[0]->sql);
		$this->assertStringContainsString('token BINARY(16)', $preview);
		$this->assertStringContainsString('payload VARBINARY(16)', $preview);
		$migrator->migrate('1');
		$this->observer->insert($this->entries, [
			'metadata' => '{}',
			'content'  => 'Example',
			'token'    => "a\0b",
			'payload'  => "a\0b",
		]);
		$row = $this->observer->fetchAssociative("SELECT
			token,
			payload
		FROM {$this->entries}
		WHERE id = 1");
		$this->assertIsArray($row);
		$this->assertSame("a\0b" . str_repeat("\0", 13), $row['token']);
		$this->assertSame("a\0b", $row['payload']);
		$this->assertSame([], $migrator->preview('1'));
		$migrator->migrate('2');
		$this->assertSame("a\0b" . str_repeat("\0", 13), $this->observer->fetchOne("SELECT payload
			FROM {$this->entries}
			WHERE id = 1"));
		$migrator->rollback();
		$this->assertFalse($this->introspect()->getColumn('payload')->getFixed());
		$this->assertSame("a\0b" . str_repeat("\0", 13), $this->observer->fetchOne("SELECT payload
			FROM {$this->entries}
			WHERE id = 1"));
	}

	/**
	 * Alter each complete definition, then restore the original attributes.
	 */
	public function test_alteration_and_rollback_preserve_values(): void {
		$migrator = $this->container->get(Migrator::class);
		$migrator->migrate('1');
		$this->insertEntry();
		$migrator->migrate('2');
		$this->assertSame([], $migrator->migrate('2'));
		$table = $this->introspect();
		$this->assertTrue($table->getColumn('metadata')->getNotnull());
		$this->assertSame('Required metadata', $table->getColumn('metadata')->getComment());
		$this->assertFalse($table->getColumn('starts_on')->getNotnull());
		$this->assertNull($table->getColumn('starts_on')->getDefault());
		$this->assertSame('10:00:00', $table->getColumn('opens_at')->getDefault());
		$this->assertFalse($table->getColumn('attempts')->getUnsigned());
		$this->assertSame(3, (int) $table->getColumn('attempts')->getDefault());
		$this->assertTrue($table->getColumn('currency')->getFixed());
		$this->assertSame(4, $table->getColumn('currency')->getLength());
		$this->assertSame('USDX', $table->getColumn('currency')->getDefault());
		$this->assertTrue($table->getColumn('content')->getNotnull());
		$this->assertFalse($table->getColumn('token')->getFixed());
		$this->assertTrue($table->getColumn('payload')->getFixed());
		$this->assertSame('Variable token', $table->getColumn('token')->getComment());
		$this->assertSame('Fixed payload', $table->getColumn('payload')->getComment());
		$this->assertEntry();
		$migrator->rollback();
		$this->assertInitialColumns();
		$this->assertEntry();
	}

	/**
	 * @return iterable<string, array{class-string<ColumnRename>}>
	 */
	public static function renameStrategies(): iterable {
		yield 'detected platform' => [
			PlatformColumnRename::class,
		];

		yield 'legacy CHANGE fallback' => [
			ChangeColumnRename::class,
		];
	}

	/**
	 * Renaming retains each type, including JSON detection on MariaDB.
	 *
	 * @param class-string<ColumnRename> $strategy
	 *
	 * @dataProvider renameStrategies
	 */
	#[DataProvider('renameStrategies')]
	public function test_column_types_survive_rename(string $strategy): void {
		$this->container->singleton(ColumnRename::class, $strategy);
		$migrator = $this->container->get(Migrator::class);
		$migrator->migrate('2');
		$this->insertEntry();
		$migrator->migrate('3');
		$this->assertSame([], $migrator->migrate('3'));
		$this->assertSame(Types::JSON, Type::lookupName($this->introspect()->getColumn('metadata_renamed')->getType()));
		$this->assertFalse($this->introspect()->getColumn('token_renamed')->getFixed());
		$this->assertTrue($this->introspect()->getColumn('payload_renamed')->getFixed());
		$migrator->rollbackTo('1');
		$this->assertInitialColumns();
		$this->assertEntry();
	}

	private function insertEntry(): void {
		$this->observer->insert($this->entries, [
			'id'        => 1,
			'metadata'  => [
				'source' => 'example',
			],
			'starts_on' => '2026-09-24',
			'opens_at'  => '12:34:56',
			'attempts'  => 2,
			'currency'  => 'CAD',
			'content'   => str_repeat('a', 70000),
			'token'     => hex2bin('00112233445566778899aabbccddeeff'),
			'payload'   => hex2bin('ffeeddccbbaa99887766554433221100'),
		], [
			'metadata' => Types::JSON,
		]);
	}

	private function assertEntry(): void {
		$row = $this->observer->fetchAssociative("SELECT
			metadata,
			starts_on,
			opens_at,
			attempts,
			currency,
			content,
			token,
			payload
		FROM {$this->entries}
		WHERE id = 1");
		$this->assertIsArray($row);
		$this->assertSame([
			'source' => 'example',
		], json_decode((string) $row['metadata'], true, flags: JSON_THROW_ON_ERROR));
		$this->assertSame('2026-09-24', $row['starts_on']);
		$this->assertSame('12:34:56', $row['opens_at']);
		$this->assertSame(2, (int) $row['attempts']);
		$this->assertSame('CAD', $row['currency']);
		$this->assertSame(str_repeat('a', 70000), $row['content']);
		$this->assertSame(hex2bin('00112233445566778899aabbccddeeff'), $row['token']);
		$this->assertSame(hex2bin('ffeeddccbbaa99887766554433221100'), $row['payload']);
	}

	private function assertInitialColumns(): void {
		$table = $this->introspect();
		$this->assertSame(Types::JSON, Type::lookupName($table->getColumn('metadata')->getType()));
		$this->assertSame(Types::DATE_MUTABLE, Type::lookupName($table->getColumn('starts_on')->getType()));
		$this->assertSame(Types::TIME_MUTABLE, Type::lookupName($table->getColumn('opens_at')->getType()));
		$this->assertSame(Types::SMALLINT, Type::lookupName($table->getColumn('attempts')->getType()));
		$this->assertFalse($table->getColumn('metadata')->getNotnull());
		$this->assertSame('External metadata', $table->getColumn('metadata')->getComment());
		$this->assertTrue($table->getColumn('currency')->getFixed());
		$this->assertSame(3, $table->getColumn('currency')->getLength());
		$this->assertSame('USD', $table->getColumn('currency')->getDefault());
		$this->assertTrue($table->getColumn('attempts')->getUnsigned());
		$this->assertSame(0, (int) $table->getColumn('attempts')->getDefault());
		$this->assertSame(16777215, $table->getColumn('content')->getLength());
		$this->assertFalse($table->getColumn('content')->getNotnull());
		$this->assertSame(Types::BINARY, Type::lookupName($table->getColumn('token')->getType()));
		$this->assertSame(Types::BINARY, Type::lookupName($table->getColumn('payload')->getType()));
		$this->assertTrue($table->getColumn('token')->getFixed());
		$this->assertFalse($table->getColumn('payload')->getFixed());
		$this->assertSame(16, $table->getColumn('token')->getLength());
		$this->assertSame(16, $table->getColumn('payload')->getLength());
		$this->assertFalse($table->getColumn('token')->getNotnull());
		$this->assertFalse($table->getColumn('payload')->getNotnull());
		$this->assertSame('Fixed bytes', $table->getColumn('token')->getComment());
		$this->assertSame('Variable bytes', $table->getColumn('payload')->getComment());
	}

	private function introspect(): Table {
		return $this->observer->createSchemaManager()->introspectTable($this->source->prefix . $this->suffix . '_entries');
	}
}
