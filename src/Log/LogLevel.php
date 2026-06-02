<?php declare(strict_types=1);

namespace StellarWP\Foundation\Log;

use UnhandledMatchError;

/**
 * A wrapper implementation for getting Monolog
 * levels.
 */
final class LogLevel
{
	/**
	 * Detailed debug information
	 */
	public const int DEBUG = 100;

	/**
	 * Interesting events
	 *
	 * Examples: User logs in, SQL logs.
	 */
	public const int INFO = 200;

	/**
	 * Uncommon events
	 */
	public const int NOTICE = 250;

	/**
	 * Exceptional occurrences that are not errors
	 *
	 * Examples: Use of deprecated APIs, poor use of an API,
	 * undesirable things that are not necessarily wrong.
	 */
	public const int WARNING = 300;

	/**
	 * Runtime errors
	 */
	public const int ERROR = 400;

	/**
	 * Critical conditions
	 *
	 * Example: Application component unavailable, unexpected exception.
	 */
	public const int CRITICAL = 500;

	/**
	 * Action must be taken immediately
	 *
	 * Example: Entire website down, database unavailable, etc.
	 * This should trigger the SMS alerts and wake you up.
	 */
	public const int ALERT = 550;

	/**
	 * Urgent alert.
	 */
	public const int EMERGENCY = 600;

	/**
	 * Get a Monolog log level code from a name.
	 *
	 * @param string $level The log level.
	 *
	 * @throws UnhandledMatchError
	 *
	 * @return int The Monolog level code.
	 */
	public static function fromName(string $level): int {
		return match ($level) {
			default => throw new UnhandledMatchError($level),
			'debug', 'Debug', 'DEBUG' => self::DEBUG,
			'info', 'Info', 'INFO' => self::INFO,
			'notice', 'Notice', 'NOTICE' => self::NOTICE,
			'warning', 'Warning', 'WARNING' => self::WARNING,
			'error', 'Error', 'ERROR' => self::ERROR,
			'critical', 'Critical', 'CRITICAL' => self::CRITICAL,
			'alert', 'Alert', 'ALERT' => self::ALERT,
			'emergency', 'Emergency', 'EMERGENCY' => self::EMERGENCY,
		};
	}

	/**
	 * Returns the PSR-3 level matching the Monolog level code.
	 *
	 *
	 * @throws UnhandledMatchError
	 *
	 * @phpstan-return \Psr\Log\LogLevel::*
	 */
	public static function toPsrLogLevel(int $level): string {
		return match ($level) {
			default         => throw new UnhandledMatchError((string) $level),
			self::DEBUG     => \Psr\Log\LogLevel::DEBUG,
			self::INFO      => \Psr\Log\LogLevel::INFO,
			self::NOTICE    => \Psr\Log\LogLevel::NOTICE,
			self::WARNING   => \Psr\Log\LogLevel::WARNING,
			self::ERROR     => \Psr\Log\LogLevel::ERROR,
			self::CRITICAL  => \Psr\Log\LogLevel::CRITICAL,
			self::ALERT     => \Psr\Log\LogLevel::ALERT,
			self::EMERGENCY => \Psr\Log\LogLevel::EMERGENCY,
		};
	}
}
