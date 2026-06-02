<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LogLevel as PsrLogLevel;
use StellarWP\Foundation\Log\LogLevel;
use StellarWP\Foundation\Tests\TestCase;
use UnhandledMatchError;

final class LogLevelTest extends TestCase
{
	/**
	 * @return array<string, array{string, int}>
	 */
	public static function logLevelNameProvider(): array {
		return [
			'debug lowercase'     => ['debug', LogLevel::DEBUG],
			'debug titlecase'     => ['Debug', LogLevel::DEBUG],
			'debug uppercase'     => ['DEBUG', LogLevel::DEBUG],
			'info'                => ['info', LogLevel::INFO],
			'notice'              => ['notice', LogLevel::NOTICE],
			'warning'             => ['warning', LogLevel::WARNING],
			'error'               => ['error', LogLevel::ERROR],
			'critical'            => ['critical', LogLevel::CRITICAL],
			'alert'               => ['alert', LogLevel::ALERT],
			'emergency lowercase' => ['emergency', LogLevel::EMERGENCY],
			'emergency titlecase' => ['Emergency', LogLevel::EMERGENCY],
			'emergency uppercase' => ['EMERGENCY', LogLevel::EMERGENCY],
		];
	}

	#[DataProvider('logLevelNameProvider')]
	public function test_it_resolves_log_level_names(string $level, int $expected): void {
		$this->assertSame($expected, LogLevel::fromName($level));
	}

	public function test_it_throws_when_resolving_an_unknown_log_level_name(): void {
		$this->expectException(UnhandledMatchError::class);

		LogLevel::fromName('verbose');
	}

	/**
	 * @return array<string, array{int, PsrLogLevel::*}>
	 */
	public static function psrLogLevelProvider(): array {
		return [
			'debug'     => [LogLevel::DEBUG, PsrLogLevel::DEBUG],
			'info'      => [LogLevel::INFO, PsrLogLevel::INFO],
			'notice'    => [LogLevel::NOTICE, PsrLogLevel::NOTICE],
			'warning'   => [LogLevel::WARNING, PsrLogLevel::WARNING],
			'error'     => [LogLevel::ERROR, PsrLogLevel::ERROR],
			'critical'  => [LogLevel::CRITICAL, PsrLogLevel::CRITICAL],
			'alert'     => [LogLevel::ALERT, PsrLogLevel::ALERT],
			'emergency' => [LogLevel::EMERGENCY, PsrLogLevel::EMERGENCY],
		];
	}

	#[DataProvider('psrLogLevelProvider')]
	public function test_it_resolves_psr_log_level_names(int $level, string $expected): void {
		$this->assertSame($expected, LogLevel::toPsrLogLevel($level));
	}

	public function test_it_throws_when_resolving_an_unknown_psr_log_level(): void {
		$this->expectException(UnhandledMatchError::class);

		LogLevel::toPsrLogLevel(999);
	}
}
