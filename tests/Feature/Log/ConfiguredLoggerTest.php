<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Feature\Log;

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\GroupHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Container\Exceptions\ContainerException;
use StellarWP\Foundation\Log\LogProvider;
use StellarWP\Foundation\Tests\TestCase;

final class ConfiguredLoggerTest extends TestCase
{
	public function test_a_stack_combines_builtin_and_custom_handlers(): void {
		$this->configureLogger([
			'channel'  => 'stack',
			'level'    => 'warning',
			'channels' => [
				'stack'   => [
					'channels' => ['console', 'errorlog', 'audit'],
				],
				'console' => ['with' => ['stream' => 'php://memory']],
				'audit'   => ['handler' => TestHandler::class, 'formatter' => JsonFormatter::class],
			],
		]);
		$audit    = new TestHandler();
		$errorlog = new TestHandler();
		$this->container->singleton(TestHandler::class, $audit);
		$this->container->singleton(ErrorLogHandler::class, $errorlog);

		$logger = $this->container->get(LoggerInterface::class);
		$this->assertInstanceOf(Logger::class, $logger);
		$logger->info('Below threshold.');
		$logger->warning('Catalog import failed.', ['site_id' => 42]);

		$this->assertSame($logger, $this->container->get(LoggerInterface::class));
		$this->assertCount(1, $audit->getRecords());
		$this->assertCount(1, $errorlog->getRecords());
		$this->assertTrue($errorlog->hasWarning('Catalog import failed.'));
		$this->assertSame(['site_id' => 42], $errorlog->getRecords()[0]['context']);
		$this->assertInstanceOf(JsonFormatter::class, $audit->getFormatter());
		$console = $logger->getHandlers()[2];
		$this->assertInstanceOf(StreamHandler::class, $console);
		$this->assertSame([$audit, $errorlog, $console], $logger->getHandlers());
		$stream = $console->getStream();
		$this->assertIsResource($stream);
		rewind($stream);
		$this->assertStringContainsString('Catalog import failed.', stream_get_contents($stream));
	}

	public function test_configured_stack_members_replace_the_default_list(): void {
		$this->configureLogger([
			'channel'  => 'stack',
			'channels' => [
				'stack' => ['channels' => ['audit']],
				'audit' => ['handler' => TestHandler::class],
			],
		]);
		$audit = new TestHandler();
		$this->container->singleton(TestHandler::class, $audit);

		$logger = $this->container->get(LoggerInterface::class);
		$this->assertInstanceOf(Logger::class, $logger);
		$logger->debug('Uses the existing default level.');

		$this->assertSame([$audit], $logger->getHandlers());
		$this->assertTrue($audit->hasDebugRecords());
	}

	public function test_a_custom_channel_resolves_handler_dependencies_through_the_container(): void {
		$this->configureLogger([
			'channel'  => 'audit',
			'channels' => ['audit' => ['handler' => GroupHandler::class]],
		]);
		$audit = new TestHandler();
		$this->container->when(GroupHandler::class)->needs('$handlers')->give([$audit]);

		$this->container->get(LoggerInterface::class)->info('Custom handler dependency.');

		$this->assertTrue($audit->hasInfo('Custom handler dependency.'));
	}

	public function test_an_application_can_define_a_named_stack(): void {
		$this->configureLogger([
			'channel'  => 'background',
			'channels' => [
				'background' => ['channels' => ['audit']],
				'audit'      => ['handler' => TestHandler::class],
			],
		]);
		$audit = new TestHandler();
		$this->container->singleton(TestHandler::class, $audit);

		$this->container->get(LoggerInterface::class)->info('Background work.');

		$this->assertSame('background', $audit->getRecords()[0]['channel']);
	}

	public function test_an_unknown_stack_member_fails_when_the_logger_is_resolved(): void {
		$this->configureLogger([
			'channel'  => 'stack',
			'channels' => ['stack' => ['channels' => ['missing']]],
		]);

		$this->expectException(ContainerException::class);
		$this->expectExceptionMessage('log channel missing must define a handler.');

		$this->container->get(LoggerInterface::class);
	}

	public function test_an_empty_stack_discards_records(): void {
		$this->configureLogger([
			'channel'  => 'stack',
			'channels' => ['stack' => ['channels' => []]],
		]);

		$logger = $this->container->get(LoggerInterface::class);
		$this->assertInstanceOf(Logger::class, $logger);
		$logger->warning('No destinations configured.');

		$this->assertSame([], $logger->getHandlers());
	}

	/**
	 * @dataProvider selectedChannels
	 */
	#[DataProvider('selectedChannels')]
	public function test_a_console_destination_is_preserved_when_selected_through_a_stack(string $channel): void {
		$path = $this->prepare_temp_dir('log-channels') . '/console.log';
		$this->configureLogger([
			'channel'  => $channel,
			'channels' => [
				'console'    => ['with' => ['stream' => $path]],
				'stack'      => ['channels' => ['console']],
				'background' => ['channels' => ['console']],
			],
		]);

		$logger = $this->container->get(LoggerInterface::class);
		$this->assertInstanceOf(Logger::class, $logger);
		$handler = $logger->getHandlers()[0];
		$this->assertInstanceOf(StreamHandler::class, $handler);
		$this->assertSame($path, $handler->getUrl());

		$logger->info('Catalog imported.');

		$this->assertStringContainsString('Catalog imported.', (string) file_get_contents($path));
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function selectedChannels(): iterable {
		yield 'direct' => ['console'];

		yield 'default stack' => ['stack'];

		yield 'custom stack' => ['background'];
	}

	public function test_stream_channels_keep_independent_destinations_and_formatters(): void {
		$directory = $this->prepare_temp_dir('log-channels');
		$this->configureLogger([
			'channel'  => 'stack',
			'level'    => 'warning',
			'channels' => [
				'stack' => ['channels' => ['plain', 'json']],
				'plain' => [
					'handler'   => StreamHandler::class,
					'formatter' => LineFormatter::class,
					'with'      => ['stream' => $directory . '/plain.log'],
				],
				'json'  => [
					'handler'   => StreamHandler::class,
					'formatter' => JsonFormatter::class,
					'with'      => ['stream' => $directory . '/json.log'],
				],
			],
		]);
		$this->container->singleton(LineFormatter::class, new LineFormatter("%message%\n"));

		$logger = $this->container->get(LoggerInterface::class);
		$this->assertInstanceOf(Logger::class, $logger);
		[$json, $plain] = $logger->getHandlers();
		$this->assertNotSame($plain, $json);

		$logger->info('Below threshold.');
		$logger->warning('Catalog import failed.', ['site_id' => 42]);

		$this->assertSame("Catalog import failed.\n", file_get_contents($directory . '/plain.log'));
		$record = json_decode((string) file_get_contents($directory . '/json.log'), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame('Catalog import failed.', $record['message']);
		$this->assertSame(['site_id' => 42], $record['context']);
	}

	public function test_transient_custom_handlers_keep_independent_channel_formatters(): void {
		$this->configureLogger([
			'channel'  => 'stack',
			'channels' => [
				'stack' => ['channels' => ['plain', 'json']],
				'plain' => ['handler' => TestHandler::class, 'formatter' => LineFormatter::class],
				'json'  => ['handler' => TestHandler::class, 'formatter' => JsonFormatter::class],
			],
		]);

		$logger = $this->container->get(LoggerInterface::class);
		$this->assertInstanceOf(Logger::class, $logger);
		[$json, $plain] = $logger->getHandlers();
		$this->assertInstanceOf(TestHandler::class, $json);
		$this->assertInstanceOf(TestHandler::class, $plain);
		$this->assertNotSame($plain, $json);
		$this->assertInstanceOf(LineFormatter::class, $plain->getFormatter());
		$this->assertInstanceOf(JsonFormatter::class, $json->getFormatter());

		$logger->info('Both custom channels receive this.');

		$this->assertCount(1, $plain->getRecords());
		$this->assertCount(1, $json->getRecords());
	}

	public function test_a_shared_custom_handler_cannot_be_reconfigured_as_two_stack_channels(): void {
		$this->configureLogger([
			'channel'  => 'stack',
			'channels' => [
				'stack' => ['channels' => ['plain', 'json']],
				'plain' => ['handler' => TestHandler::class, 'formatter' => LineFormatter::class],
				'json'  => ['handler' => TestHandler::class, 'formatter' => JsonFormatter::class],
			],
		]);
		$this->container->singleton(TestHandler::class, new TestHandler());

		$this->expectException(ContainerException::class);
		$this->expectExceptionMessage('shares a handler instance');

		$this->container->get(LoggerInterface::class);
	}

	public function test_a_single_custom_channel_can_keep_its_shared_handlers_formatter(): void {
		$this->configureLogger([
			'channel'  => 'console',
			'channels' => [
				'console' => ['handler' => TestHandler::class, 'formatter' => null],
			],
		]);
		$handler   = new TestHandler();
		$formatter = new JsonFormatter();
		$handler->setFormatter($formatter);
		$this->container->singleton(TestHandler::class, $handler);

		$this->container->get(LoggerInterface::class)->info('Use the configured handler.');

		$this->assertSame($formatter, $handler->getFormatter());
		$this->assertTrue($handler->hasInfo('Use the configured handler.'));
	}

	public function test_a_non_bubbling_handler_stops_delivery_to_earlier_stack_entries(): void {
		$this->configureLogger([
			'channel'  => 'stack',
			'channels' => [
				'stack' => ['channels' => ['audit', 'stop']],
				'audit' => ['handler' => TestHandler::class],
				'stop'  => ['handler' => GroupHandler::class],
			],
		]);
		$audit = new TestHandler();
		$last  = new TestHandler();
		$this->container->singleton(TestHandler::class, $audit);
		$this->container->singleton(GroupHandler::class, new GroupHandler([$last], false));

		$this->container->get(LoggerInterface::class)->warning('Handled by the last entry.');

		$this->assertTrue($last->hasWarning('Handled by the last entry.'));
		$this->assertSame([], $audit->getRecords());
	}

	public function test_an_application_can_replace_the_logger_before_it_resolves(): void {
		$this->configureLogger(['channel' => 'invalid']);
		$logger = new NullLogger();
		$this->container->singleton(LoggerInterface::class, $logger);

		$this->assertSame($logger, $this->container->get(LoggerInterface::class));
	}

	/**
	 * @param array<string, mixed> $configuration
	 */
	private function configureLogger(array $configuration): void {
		$dataDirectory   = $this->data_dir();
		$this->container = (new ContainerFactory())->create(new ArrayConfiguration(['log' => $configuration]));
		$this->container->singleton(TestCase::DATA_DIR, $dataDirectory);
		$this->container->register(LogProvider::class);
	}
}
