<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Feature\Log;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use phpmock\mockery\PHPMockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Log\LoggerInterface;
use StellarWP\Foundation\Log\Handlers\NullHandler;
use StellarWP\Foundation\Log\LogProvider;
use StellarWP\Foundation\Tests\TestCase;

final class UnavailableErrorLogLoggerTest extends TestCase
{
	protected function setUp(): void {
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_falls_back_to_the_null_handler_when_error_log_is_unavailable(): void {
		$_ENV['TEST_LOG_CHANNEL'] = LogProvider::CHANNEL_ERRORLOG;

		parent::setUp();

		PHPMockery::mock('StellarWP\Foundation\Log', 'function_exists')
			->with('error_log')
			->andReturn(false);

		$logger = $this->container->get(LoggerInterface::class);

		$this->assertInstanceOf(Logger::class, $logger);
		$this->assertCount(1, $logger->getHandlers());
		$this->assertInstanceOf(NullHandler::class, $logger->getHandlers()[0]);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_skips_the_error_log_handler_in_stack_channels_when_error_log_is_unavailable(): void {
		$_ENV['TEST_LOG_CHANNEL'] = 'stack';

		parent::setUp();

		PHPMockery::mock('StellarWP\Foundation\Log', 'function_exists')
			->with('error_log')
			->andReturn(false);

		$logger = $this->container->get(LoggerInterface::class);

		$this->assertInstanceOf(Logger::class, $logger);
		$this->assertCount(1, $logger->getHandlers());
		$this->assertInstanceOf(StreamHandler::class, $logger->getHandlers()[0]);
	}
}
