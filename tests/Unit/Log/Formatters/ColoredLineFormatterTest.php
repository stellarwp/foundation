<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Log\Formatters;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LogLevel as PsrLogLevel;
use StellarWP\Foundation\Log\Formatters\ColoredLineFormatter;
use StellarWP\Foundation\Tests\TestCase;

final class ColoredLineFormatterTest extends TestCase
{
	/**
	 * @dataProvider coloredFormats
	 */
	#[DataProvider('coloredFormats')]
	public function test_it_formats_records_with_a_custom_color_scheme(string $format, int $mode, string $expected): void {
		$formatter = new ColoredLineFormatter(
			$format,
			colorScheme: [
				PsrLogLevel::ERROR => '[error]',
			],
			colorMode: $mode,
		);
		$handler = new StreamHandler('php://memory');
		$handler->setFormatter($formatter);
		$logger = new Logger('tests', [
			$handler,
		]);
		$logger->error('Something happened');
		$stream = $handler->getStream();
		$this->assertIsResource($stream);
		rewind($stream);

		$this->assertSame(
			$expected,
			stream_get_contents($stream),
		);
	}

	/**
	 * @return iterable<string, array{string, int, string}>
	 */
	public static function coloredFormats(): iterable {
		yield 'explicit color markers' => [
			'%color_start%%level_name%%color_end% %message%',
			ColoredLineFormatter::MODE_COLOR_LEVEL_ALL,
			"[error]ERROR\033[0m Something happened",
		];

		yield 'color every level marker' => [
			'%level_name% %level_name% %message%',
			ColoredLineFormatter::MODE_COLOR_LEVEL_ALL,
			"[error]ERROR\033[0m [error]ERROR\033[0m Something happened",
		];

		yield 'color only the first level marker' => [
			'%level_name% %level_name% %message%',
			ColoredLineFormatter::MODE_COLOR_LEVEL_FIRST,
			"[error]ERROR\033[0m ERROR Something happened",
		];
	}
}
