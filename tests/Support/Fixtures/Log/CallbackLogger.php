<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Log;

use Closure;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Invoke a test callback when an operation logs a message.
 */
final class CallbackLogger extends AbstractLogger
{
	/**
	 * Receive the test action to invoke for each log message.
	 *
	 * @param Closure(string, array<string, mixed>): void $callback
	 */
	public function __construct(
		private readonly Closure $callback,
	) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string|Stringable $message
	 */
	public function log($level, $message, array $context = []): void {
		($this->callback)((string) $message, $context);
	}
}
