<?php declare(strict_types=1);

namespace StellarWP\Foundation\Log;

use InvalidArgumentException;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractHandler;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\Container\Exceptions\ContainerException;
use StellarWP\Foundation\Log\Formatters\ColoredLineFormatter;
use StellarWP\Foundation\Log\Handlers\NullHandler;
use UnhandledMatchError;

/**
 * Registers the configured PSR-3 logger with built-in or application-defined channels.
 */
final class LogProvider extends Provider
{
	private const string CHANNEL_ERRORLOG = 'errorlog';
	private const string CHANNEL_CONSOLE  = 'console';
	private const string CHANNEL_NULL     = 'null';
	private const string CHANNEL_STACK    = 'stack';
	private const array CHANNELS          = [
		self::CHANNEL_CONSOLE  => [
			'handler'   => StreamHandler::class,
			'formatter' => ColoredLineFormatter::class,
		],
		self::CHANNEL_ERRORLOG => [
			'handler'   => ErrorLogHandler::class,
			'formatter' => LineFormatter::class,
		],
		self::CHANNEL_STACK    => [
			'channels' => [self::CHANNEL_CONSOLE, self::CHANNEL_ERRORLOG],
		],
		self::CHANNEL_NULL     => [
			'handler' => NullHandler::class,
		],
	];

	/**
	 * Register the application logger with its configured channel and level.
	 *
	 * @throws ContainerException  When service bindings cannot be registered.
	 * @throws UnhandledMatchError When the configured log level is unsupported.
	 */
	public function register(): void {
		$level = LogLevel::fromName($this->config->get('log.level', 'debug'));

		$this->container->when(ColoredLineFormatter::class)
			->needs('$dateFormat')
			->give('Y-m-d H:i:s.v e');

		$this->container->singleton(
			LoggerInterface::class,
			fn (C $c): LoggerInterface => $this->createLogger($c, $level)
		);
	}

	/**
	 * Build the selected logger from its channels' destinations and formatting.
	 *
	 * @param LogLevel::* $level
	 *
	 * @throws InvalidArgumentException When a configured stream is neither a path nor a resource.
	 * @throws RuntimeException         When a channel is invalid or channels share a handler instance.
	 * @throws ContainerException       When a configured handler or formatter cannot be resolved.
	 */
	private function createLogger(C $c, int $level): Logger {
		$channel  = $this->config->get('log.channel');
		$channels = self::CHANNELS;

		foreach ($this->config->get('log.channels', []) as $name => $definition) {
			$channels[$name] = array_replace($channels[$name] ?? [], $definition);
		}

		$selected = $channels[$channel] ?? null;

		if ($selected === null) {
			throw new RuntimeException(sprintf(
				'Invalid log channel. Valid options are: %s',
				implode(',', array_keys($channels))
			));
		}

		$logger = new Logger($channel);

		foreach ($selected['channels'] ?? [$channel] as $name) {
			$definition = $channels[$name] ?? [];

			if (! isset($definition['handler'])) {
				throw new RuntimeException(sprintf('Log channel "%s" must define a handler.', $name));
			}

			if ($definition['handler'] === ErrorLogHandler::class && ! function_exists('error_log')) {
				if (isset($selected['channels'])) {
					continue;
				}

				$definition = self::CHANNELS[self::CHANNEL_NULL];
			}

			$handler = $definition['handler'] === StreamHandler::class
				? new StreamHandler($definition['with']['stream'] ?? 'php://stdout', $level)
				: $c->get($definition['handler']);

			if (in_array($handler, $logger->getHandlers(), true)) {
				throw new RuntimeException(sprintf(
					'Log channel "%s" shares a handler instance with another stack entry. Use separate handler bindings or a single shared channel.',
					$name
				));
			}

			if (isset($definition['formatter']) && $handler instanceof FormattableHandlerInterface) {
				$handler->setFormatter($c->get($definition['formatter']));
			}

			if ($handler instanceof AbstractHandler) {
				$handler->setLevel($level);
			}

			$logger->pushHandler($handler);
		}

		return $logger;
	}
}
