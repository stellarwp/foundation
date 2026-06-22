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

	public function test_it_fails_when_input_cannot_be_normalized_to_a_class_name(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not create a class name from "@@@".');

		(new WordPressClassNameResolver())->commandClass('@@@');
	}

	public function test_it_uses_a_default_description_when_the_class_has_no_words(): void {
		$this->assertSame('Run the command.', (new WordPressClassNameResolver())->description('Command'));
	}
}
