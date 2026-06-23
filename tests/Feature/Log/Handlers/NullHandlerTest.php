<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Feature\Log\Handlers;

use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Log\Handlers\NullHandler;
use StellarWP\Foundation\Log\LogLevel;
use StellarWP\Foundation\Tests\TestCase;

final class NullHandlerTest extends TestCase
{
	private NullHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->handler = $this->container->get(NullHandler::class);
	}

	/**
	 * @param array{level: int, result: bool} $input
	 *
	 * @dataProvider logProvider
	 */
	#[DataProvider('logProvider')]
	public function test_it_null_logs_valid_levels(array $input): void {
		$this->assertSame(
			$input['result'],
			// @phpstan-ignore-next-line
			$this->handler->handle([
				'level' => $input['level'],
			])
		);
	}

	/**
	 * @return array<int, array<array{level: int, result: bool}>>
	 */
	public static function logProvider(): array {
		return [
			[
				[
					'level'  => 1,
					'result' => false,
				],
			],
			[
				[
					'level'  => 50,
					'result' => false,
				],
			],
			[
				[
					'level'  => LogLevel::DEBUG,
					'result' => true,
				],
			],
			[
				[
					'level'  => LogLevel::INFO,
					'result' => true,
				],
			],
			[
				[
					'level'  => LogLevel::NOTICE,
					'result' => true,
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
