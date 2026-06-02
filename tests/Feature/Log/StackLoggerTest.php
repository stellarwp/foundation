<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Feature\Log;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Log\LoggerInterface;
use StellarWP\Foundation\Log\Formatters\ColoredLineFormatter;
use StellarWP\Foundation\Tests\TestCase;

final class StackLoggerTest extends TestCase
{
	/**
	 * env var set in phpunit.xml.dist.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_has_the_correct_logger_handlers_when_running_tests(): void {
		$logger = $this->container->get(LoggerInterface::class);
		$this->assertInstanceOf(Logger::class, $logger);
		$this->assertCount(2, $logger->getHandlers());

		$this->assertSame('debug', $_ENV['TEST_LOG_LEVEL']);

		/** @var AbstractProcessingHandler $handler */
		foreach ($logger->getHandlers() as $handler) {
			// https://github.com/Seldaek/monolog/blob/main/doc/01-usage.md#log-levels
			$this->assertSame(100, $handler->getLevel());

			$this->assertThat($handler, $this->logicalOr(
				$this->isInstanceOf(ErrorLogHandler::class),
				$this->isInstanceOf(StreamHandler::class),
			));

			$this->assertThat($handler->getFormatter(), $this->logicalOr(
				$this->isInstanceOf(ColoredLineFormatter::class),
				$this->isInstanceOf(LineFormatter::class),
			));
		}
	}
}
