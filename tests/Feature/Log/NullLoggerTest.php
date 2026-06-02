<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Feature\Log;

use Monolog\Logger;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Log\LoggerInterface;
use StellarWP\Foundation\Log\Handlers\NullHandler;
use StellarWP\Foundation\Tests\TestCase;

final class NullLoggerTest extends TestCase
{
	protected function setUp(): void {
		$_ENV['TEST_LOG_CHANNEL'] = 'null';

		parent::setUp();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_can_change_log_channels(): void {
		$logger = $this->container->get(LoggerInterface::class);
		$this->assertInstanceOf(Logger::class, $logger);
		$handler = $logger->getHandlers()[0];
		$this->assertInstanceOf(NullHandler::class, $handler);
	}
}
