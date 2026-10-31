<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Generation;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use StellarWP\Foundation\Cli\Generation\WordPressClassNameResolver;
use StellarWP\Foundation\Tests\TestCase;

final class WordPressClassNameResolverTest extends TestCase
{
	/**
	 * @return array<string,array{input: string, expected: string}>
	 */
	public static function commandClassProvider(): array {
		return [
			'snake command'  => [
				'input'    => 'Sync_Products_Command',
				'expected' => 'Sync_Products_Command',
			],
			'snake base'     => [
				'input'    => 'Sync_Products',
				'expected' => 'Sync_Products_Command',
			],
			'kebab base'     => [
				'input'    => 'sync-products',
				'expected' => 'Sync_Products_Command',
			],
			'camel command'  => [
				'input'    => 'SyncProductsCommand',
				'expected' => 'Sync_Products_Command',
			],
			'path-like name' => [
				'input'    => 'Cli/SyncProducts',
				'expected' => 'Sync_Products_Command',
			],
		];
	}

	/**
	 * @dataProvider commandClassProvider
	 */
	#[DataProvider('commandClassProvider')]
	public function test_it_normalizes_command_class_names(string $input, string $expected): void {
		$this->assertSame($expected, (new WordPressClassNameResolver())->commandClass($input));
	}

	public function test_it_creates_a_subcommand_from_a_command_class(): void {
		$this->assertSame('sync-products', (new WordPressClassNameResolver())->subcommand('Sync_Products_Command'));
	}

	public function test_it_creates_a_description_from_a_command_class(): void {
		$this->assertSame('Sync products.', (new WordPressClassNameResolver())->description('Sync_Products_Command'));
	}

	public function test_it_normalizes_generic_wordpress_class_names(): void {
		$this->assertSame('Bump_Version', (new WordPressClassNameResolver())->className('bump-version'));
	}

	public function test_it_normalizes_table_class_names(): void {
		$this->assertSame('Reports_Table', (new WordPressClassNameResolver())->tableClass('reports'));
		$this->assertSame('Reports_Table', (new WordPressClassNameResolver())->tableClass('Reports_Table'));
	}

	public function test_it_creates_table_names_from_table_classes(): void {
		$this->assertSame('reports', (new WordPressClassNameResolver())->tableName('Reports_Table'));
	}

	public function test_it_uses_the_lowercase_class_name_when_a_table_name_has_no_words(): void {
		$this->assertSame('@@@', (new WordPressClassNameResolver())->tableName('@@@'));
	}

	public function test_it_creates_timestamped_migration_ids_from_migration_classes(): void {
		$this->assertSame(
			'2026_06_26_120000_create_reports_table',
			(new WordPressClassNameResolver())->migrationId('Create_Reports_Table', new \DateTimeImmutable('2026-06-26 12:00:00'))
		);
	}

	public function test_it_fails_when_input_cannot_be_normalized_to_a_class_name(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not create a class name from "@@@".');

		(new WordPressClassNameResolver())->commandClass('@@@');
	}

	public function test_it_fails_when_generic_class_input_cannot_be_normalized_to_a_class_name(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not create a class name from "@@@".');

		(new WordPressClassNameResolver())->className('@@@');
	}

	public function test_it_fails_when_the_generated_class_name_would_start_with_a_number(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not create a valid PHP class name from "2fa-sync".');

		(new WordPressClassNameResolver())->commandClass('2fa-sync');
	}

	public function test_it_fails_when_the_generic_class_name_would_start_with_a_number(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not create a valid PHP class name from "2fa-sync".');

		(new WordPressClassNameResolver())->className('2fa-sync');
	}

	public function test_it_fails_when_the_generated_class_name_would_conflict_with_the_base_command(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not create a command class named "Command" from "Command"');

		(new WordPressClassNameResolver())->commandClass('Command');
	}

	public function test_it_fails_when_table_input_cannot_be_normalized_to_a_class_name(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not create a table class name from "@@@".');

		(new WordPressClassNameResolver())->tableClass('@@@');
	}

	public function test_it_fails_when_the_table_class_name_would_start_with_a_number(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not create a valid PHP class name from "2fa".');

		(new WordPressClassNameResolver())->tableClass('2fa');
	}

	public function test_it_fails_when_migration_input_cannot_be_normalized_to_an_id(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not create a migration id from "@@@".');

		(new WordPressClassNameResolver())->migrationId('@@@');
	}

	public function test_it_uses_a_default_description_when_the_class_has_no_words(): void {
		$this->assertSame('Run the command.', (new WordPressClassNameResolver())->description('Command'));
	}
}
