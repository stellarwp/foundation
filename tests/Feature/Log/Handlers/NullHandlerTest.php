<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Feature\Log\Handlers;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Log\Handlers\NullHandler;
use StellarWP\Foundation\Log\LogLevel;
use StellarWP\Foundation\Tests\TestCase;

final class NullHandlerTest extends TestCase
{
	/**
	 * @param array{level: LogLevel::*, result: bool} $input
	 *
	 * @dataProvider logProvider
	 */
	#[DataProvider('logProvider')]
	public function test_it_discards_matching_records_and_leaves_lower_levels_for_other_handlers(array $input): void {
		$captured = new TestHandler();
		$logger   = new Logger('tests', [
			new NullHandler(LogLevel::WARNING),
			$captured,
		]);

		$logger->log(LogLevel::toPsrLogLevel($input['level']), 'A record subject to the null threshold.');

		$this->assertCount($input['result'] ? 0 : 1, $captured->getRecords());
	}

	/**
	 * @return array<int, array<array{level: LogLevel::*, result: bool}>>
	 */
	public static function logProvider(): array {
		return [
			[
				[
					'level'  => LogLevel::DEBUG,
					'result' => false,
				],
			],
			[
				[
					'level'  => LogLevel::INFO,
					'result' => false,
				],
			],
			[
				[
					'level'  => LogLevel::NOTICE,
					'result' => false,
				],
			],
			[
				[
					'level'  => LogLevel::WARNING,
					'result' => true,
				],
			],
			[
				[
					'level'  => LogLevel::ERROR,
					'result' => true,
				],
			],
			[
				[
					'level'  => LogLevel::CRITICAL,
					'result' => true,
				],
			],
			[
				[
					'level'  => LogLevel::ALERT,
					'result' => true,
				],
			],
			[
				[
					'level'  => LogLevel::EMERGENCY,
					'result' => true,
				],
			],
		];
	}
}
