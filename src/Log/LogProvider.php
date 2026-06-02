<?php declare(strict_types=1);

namespace StellarWP\Foundation\Log;

use lucatume\DI52\Container;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractHandler;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Log\Formatters\ColoredLineFormatter;
use StellarWP\Foundation\Log\Handlers\NullHandler;

/**
 * Default logging provider for consumers that want Foundation to wire logging for them.
 *
 * This provider is intentionally conservative: if the configured logging transport is
 * unavailable, the application should keep running. Consumers that need different
 * channels, handlers, or failure behavior can register their own provider instead.
 */
final class LogProvider extends Provider
{
	public const string LOG_LEVEL        = 'foundation.log.log_level';
	public const string CHANNEL_ERRORLOG = 'errorlog';
	private const string CHANNEL_CONSOLE = 'console';
	private const string CHANNEL_NULL    = 'null';
	private const string CHANNEL_STACK   = 'stack';
	public const array  CHANNELS         = [
		self::CHANNEL_CONSOLE  => [
			'class'     => StreamHandler::class,
			'formatter' => ColoredLineFormatter::class,
		],
		self::CHANNEL_ERRORLOG => [
			'class'     => ErrorLogHandler::class,
			'formatter' => LineFormatter::class,
		],
		self::CHANNEL_STACK    => [
			self::CHANNEL_CONSOLE,
			self::CHANNEL_ERRORLOG,
		],
		self::CHANNEL_NULL     => [
			'class' => NullHandler::class,
		],
	];

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$this->container->singleton(self::LOG_LEVEL, LogLevel::fromName($this->config->get('log.level', 'debug')));

		$this->container->when(ColoredLineFormatter::class)
			->needs('$dateFormat')
			->give('Y-m-d H:i:s.v e');

		$channel = $this->config->get('log.channel');

		$this->container->singleton(
			StreamHandler::class,
			fn ($c) => new StreamHandler(
				$this->config->get("log.channels.$channel.with.stream", 'php://stdout'),
				$c->get(self::LOG_LEVEL)
			)
		);

		$this->container->bind(
			LoggerInterface::class,
			static function (Container $c) use ($channel): LoggerInterface {
				$handler = self::CHANNELS[$channel] ?? false;

				if (! $handler) {
					throw new RuntimeException(
						sprintf(
							'Invalid log channel. Valid options are: %s',
							implode(',', array_keys(self::CHANNELS))
						)
					);
				}

				$logger = new Logger($channel);

				/**
				 * @var array<array{handler: AbstractHandler, formatter: string|class-string}> $handlers
				 */
				$handlers = [];

				// Single handler channel.
				if (! empty($handler['class'])) {
					if ($channel === self::CHANNEL_ERRORLOG && ! self::isErrorLogAvailable()) {
						$handler = self::CHANNELS[self::CHANNEL_NULL];
					}

					$handlers[] = [
						'handler'   => $c->get($handler['class']),
						'formatter' => $handler['formatter'] ?? '',
					];
				} else {
					// We are on a stack channel, which uses multiple existing handlers.
					foreach ($handler as $stackChannel) {
						if ($stackChannel === self::CHANNEL_ERRORLOG && ! self::isErrorLogAvailable()) {
							continue;
						}

						$handlers[] = [
							'handler'   => $c->get(self::CHANNELS[$stackChannel]['class']),
							'formatter' => self::CHANNELS[$stackChannel]['formatter'],
						];
					}
				}

				/** @var array{handler: AbstractHandler, formatter: string|class-string} $registeredHandler */
				foreach ($handlers as $registeredHandler) {
					if (! empty($registeredHandler['formatter']) && $registeredHandler['handler'] instanceof FormattableHandlerInterface) {
						$registeredHandler['handler']->setFormatter($c->get($registeredHandler['formatter']));
					}

					// Set the configured log level for each handler.
					$registeredHandler['handler']->setLevel($c->get(self::LOG_LEVEL));

					$logger->pushHandler($registeredHandler['handler']);
				}

				return $logger;
			}
		);
	}

	private static function isErrorLogAvailable(): bool {
		return function_exists('error_log');
	}
}
