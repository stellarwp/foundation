<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Log\Formatters;

use DateTimeImmutable;
use Psr\Log\LogLevel as PsrLogLevel;
use StellarWP\Foundation\Log\Formatters\ColoredLineFormatter;
use StellarWP\Foundation\Log\LogLevel;
use StellarWP\Foundation\Tests\TestCase;

final class ColoredLineFormatterTest extends TestCase
{
	public function test_it_formats_records_with_a_custom_color_scheme(): void {
		$formatter = new ColoredLineFormatter(
			'%color_start%%level_name%%color_end% %message%',
			colorScheme: [
				PsrLogLevel::ERROR => '[error]',
			]
		);

		$this->assertSame(
			'[error]ERROR' . "\033[0m" . ' Something happened',
			$formatter->format([
				'message'    => 'Something happened',
				'context'    => [],
				'level'      => LogLevel::ERROR,
				'level_name' => 'ERROR',
				'channel'    => 'tests',
				'datetime'   => new DateTimeImmutable(),
				'extra'      => [],
			])
		);
	}
}
