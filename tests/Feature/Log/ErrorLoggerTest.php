<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Feature\Log;

use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Log\LoggerInterface;
use StellarWP\Foundation\Tests\TestCase;

final class ErrorLoggerTest extends TestCase
{
	protected function setUp(): void {
		$_ENV['TEST_LOG_CHANNEL'] = 'errorlog';

		parent::setUp();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_can_change_log_channels(): void {
		$logger = $this->container->get(LoggerInterface::class);
		$this->assertInstanceOf(Logger::class, $logger);
		$handler = $logger->getHandlers()[0];
		$this->assertInstanceOf(ErrorLogHandler::class, $handler);
	}
}
