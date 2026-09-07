<?php declare(strict_types=1);

namespace StellarWP\Foundation\Log;

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
	private const string LOG_LEVEL        = self::class . '.log_level';
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
		$this->container->singleton(self::LOG_LEVEL, LogLevel::fromName($this->config->get('log.level', 'debug')));

		$this->container->when(ColoredLineFormatter::class)
			->needs('$dateFormat')
			->give('Y-m-d H:i:s.v e');

		$channel            = $this->config->get('log.channel');
		$configuredChannels = $this->config->get('log.channels', []);

		$this->container->singleton(
			StreamHandler::class,
			fn (C $c) => new StreamHandler(
				$this->config->get("log.channels.$channel.with.stream", 'php://stdout'),
				$c->get(self::LOG_LEVEL)
			)
		);

		$this->container->singleton(
			LoggerInterface::class,
			static function (C $c) use ($channel, $configuredChannels): LoggerInterface {
				$channels = self::CHANNELS;

				foreach ($configuredChannels as $name => $definition) {
					$channels[$name] = array_replace($channels[$name] ?? [], $definition);
				}

				$selected = $channels[$channel] ?? null;

				if ($selected === null) {
					throw new RuntimeException(
						sprintf(
							'Invalid log channel. Valid options are: %s',
							implode(',', array_keys($channels))
						)
					);
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

					$handler = $c->get($definition['handler']);

					if (isset($definition['formatter']) && $handler instanceof FormattableHandlerInterface) {
						$handler->setFormatter($c->get($definition['formatter']));
					}

					if ($handler instanceof AbstractHandler) {
						$handler->setLevel($c->get(self::LOG_LEVEL));
					}

					$logger->pushHandler($handler);
				}

				return $logger;
			}
		);
	}
}
