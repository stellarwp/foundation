<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Migrations;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Migrations\Schema\Renames\ChangeColumnRename;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;

/**
 * Legacy CHANGE must preserve actual catalog attributes, not only portable Doctrine metadata.
 */
final class ChangeColumnRenameTest extends DatabaseTestCase
{
	/**
	 * @return iterable<string, array{string}>
	 */
	public static function definitions(): iterable {
		yield 'precision and unsigned' => [
			'DECIMAL(15, 4) UNSIGNED NOT NULL DEFAULT 12.3400',
		];

		yield 'SQL null' => [
			'VARCHAR(30) NULL DEFAULT NULL',
		];

		yield 'literal NULL and collation' => [
			"VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'NULL' COMMENT 'Owner''s value'",
		];

		yield 'empty default' => [
			"VARCHAR(30) NOT NULL DEFAULT ''",
		];

		yield 'quoted default' => [
			"VARCHAR(30) NOT NULL DEFAULT 'It''s ready'",
		];

		yield 'temporal expression and update' => [
			'DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)',
		];

		yield 'stored generation' => [
			'INT GENERATED ALWAYS AS (id + 1) STORED',
		];

		yield 'virtual generation' => [
			'INT GENERATED ALWAYS AS (id + 1) VIRTUAL',
		];
	}

	/**
	 * Rename without changing the server's complete column description.
	 *
	 * @dataProvider definitions
	 */
	#[DataProvider('definitions')]
	public function test_change_preserves_catalog_attributes(string $definition): void {
		$this->observer->executeStatement("ALTER TABLE {$this->table}\n\t\t\tADD original {$definition}");
		$this->assertPreserved('original', 'renamed');
	}

	/**
	 * Auto-increment remains attached to the renamed primary-key column.
	 */
	public function test_auto_increment_survives(): void {
		$this->observer->executeStatement("ALTER TABLE {$this->table}\n\t\t\tMODIFY id INT NOT NULL AUTO_INCREMENT");
		$this->assertPreserved('id', 'renamed_id');
		$this->observer->insert($this->table, [
			'name' => 'Next',
		]);
		$this->assertSame(2, (int) $this->observer->lastInsertId());
	}

	/**
	 * MariaDB's invisible generated columns remain invisible after CHANGE.
	 */
	public function test_generated_column_visibility_survives(): void {
		if (! $this->db->getDatabasePlatform() instanceof MariaDBPlatform) {
			$this->markTestSkipped('This declaration uses MariaDB generated-column visibility.');
		}

		$this->observer->executeStatement("ALTER TABLE {$this->table}\n\t\t\tADD original INT GENERATED ALWAYS AS (id + 1) VIRTUAL INVISIBLE");
		$this->assertPreserved('original', 'renamed');
	}

	/**
	 * @param non-empty-string $from
	 * @param non-empty-string $to
	 */
	private function assertPreserved(string $from, string $to): void {
		$strategy = new ChangeColumnRename($this->db);
		$name     = $this->source->prefix . $this->suffix;
		$table    = $strategy->inspect($this->db->createSchemaManager()->introspectTable($name));
		$before   = $this->metadata($from);

		foreach ($strategy->sql($table, $from, $to) as $sql) {
			$this->db->executeStatement($sql);
		}

		$this->assertSame($before, $this->metadata($to));
		$this->assertSame(1, (int) $this->observer->fetchOne("SELECT COUNT(*) FROM {$this->table}"));
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function metadata(string $column): array {
		return $this->observer->fetchAllAssociative(
			'SELECT
				COLUMN_TYPE,
				IS_NULLABLE,
				COLUMN_DEFAULT,
				CHARACTER_SET_NAME,
				COLLATION_NAME,
				COLUMN_COMMENT,
				EXTRA,
				GENERATION_EXPRESSION
			FROM information_schema.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = ?
				AND COLUMN_NAME = ?',
			[
				$this->source->prefix . $this->suffix,
				$column,
			],
		);
	}
}
