<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\WPCli\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Tests\TestCase;
use StellarWP\Foundation\WPCli\ValueObjects\CommandPrefix;

final class CommandPrefixTest extends TestCase
{
	public function test_it_preserves_the_configured_prefix(): void {
		$this->assertSame('your-plugin', (new CommandPrefix('your-plugin'))->value);
	}

	/**
	 * @dataProvider invalidPrefixes
	 */
	#[DataProvider('invalidPrefixes')]
	public function test_it_rejects_an_invalid_prefix(string $prefix): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot be empty or contain surrounding whitespace');

		new CommandPrefix($prefix);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalidPrefixes(): iterable {
		yield 'empty' => [''];

		yield 'spaces' => ['   '];

		yield 'leading whitespace' => [' your-plugin'];

		yield 'trailing whitespace' => ['your-plugin '];
	}
}
