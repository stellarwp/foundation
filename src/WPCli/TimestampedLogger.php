<?php declare(strict_types=1);

namespace StellarWP\Foundation\WPCli;

use DateTimeImmutable;
use DateTimeZone;
use WP_CLI\Loggers\Regular;

/**
 * WP-CLI logger decorator that prepends timestamps to command output.
 *
 * Use this when long-running WP-CLI tasks need every line to show when it was
 * emitted while preserving WP-CLI's regular logger behavior.
 */
final readonly class TimestampedLogger
{
	public function __construct(
		private Regular $wpLogger,
		private string $dateFormat = 'Y-m-d H:i:s.v e',
		private string $timezone = 'UTC'
	) {
	}

	/**
	 * @param bool|string $group Organize debug messages into a specific group. Use false for no group.
	 */
	public function debug(string $message, bool|string $group = false): void {
		$this->wpLogger->debug($this->prependTimestamp($message), $group);
	}

	public function info(string $message): void {
		$this->wpLogger->info($this->prependTimestamp($message));
	}

	public function success(string $message): void {
		$this->wpLogger->success($this->prependTimestamp($message));
	}

	public function warning(string $message): void {
		$this->wpLogger->warning($this->prependTimestamp($message));
	}

	/**
	 * @param list<string> $messageLines Messages to write.
	 */
	public function error_multi_line(array $messageLines): void {
		$this->wpLogger->error_multi_line(array_map([$this, 'prependTimestamp'], $messageLines));
	}

	private function prependTimestamp(string $message): string {
		$timestamp = (new DateTimeImmutable(
			'now',
			new DateTimeZone($this->timezone)
		))->format($this->dateFormat);

		return sprintf('[%s] %s', $timestamp, $message);
	}
}
