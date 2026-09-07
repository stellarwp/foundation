<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Feature\Log;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\GroupHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
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
				'stack' => [
					'channels' => ['console', 'errorlog', 'audit'],
					'with'     => ['stream' => 'php://memory'],
				],
				'audit' => ['handler' => TestHandler::class, 'formatter' => JsonFormatter::class],
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
		$console = $this->container->get(StreamHandler::class);
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
	 * @param array<string, mixed> $configuration
	 */
	private function configureLogger(array $configuration): void {
		$this->container = (new ContainerFactory())->create(new ArrayConfiguration(['log' => $configuration]));
		$this->container->register(LogProvider::class);
	}
}
