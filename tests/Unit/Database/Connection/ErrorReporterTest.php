<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Connection;

use mysqli_driver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StellarWP\Foundation\Database\Connection\ErrorReporter;

final class ErrorReporterTest extends TestCase
{
	/**
	 * @return iterable<string, array{int}>
	 */
	public static function modes(): iterable {
		yield 'WordPress reporting disabled' => [
			MYSQLI_REPORT_OFF,
		];

		yield 'caller reporting errors without exceptions' => [
			MYSQLI_REPORT_ERROR,
		];
	}

	/**
	 * @dataProvider modes
	 */
	#[DataProvider('modes')]
	public function test_nested_calls_restore_reporting(int $mode): void {
		$driver              = new mysqli_driver();
		$original            = $driver->report_mode;
		$reporter            = new ErrorReporter();
		$driver->report_mode = $mode;

		try {
			$reporter->run(function () use ($reporter): void {
				$this->assertSame(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, (new mysqli_driver())->report_mode);
				$reporter->run(function (): void {
					$this->assertSame(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, (new mysqli_driver())->report_mode);
				});
				$this->assertSame(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, (new mysqli_driver())->report_mode);
			});

			$this->assertSame($mode, (new mysqli_driver())->report_mode);
		} finally {
			$driver->report_mode = $original;
		}
	}

	/**
	 * @dataProvider modes
	 */
	#[DataProvider('modes')]
	public function test_failure_restores_reporting_and_preserves_the_exception(int $mode): void {
		$driver   = new mysqli_driver();
		$original = $driver->report_mode;
		$failure  = new RuntimeException('Driver failure');
		$this->expectExceptionObject($failure);
		$driver->report_mode = $mode;

		try {
			(new ErrorReporter())->run(static fn () => throw $failure);
		} catch (RuntimeException $caught) {
			$this->assertSame($failure, $caught);
			$this->assertSame($mode, (new mysqli_driver())->report_mode);

			throw $caught;
		} finally {
			$driver->report_mode = $original;
		}
	}
}
