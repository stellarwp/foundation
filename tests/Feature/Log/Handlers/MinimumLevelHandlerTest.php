<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Feature\Log\Handlers;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\BufferHandler;
use Monolog\Handler\GroupHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use StellarWP\Foundation\Log\Handlers\MinimumLevelHandler;
use StellarWP\Foundation\Log\LogLevel;
use StellarWP\Foundation\Tests\TestCase;

final class MinimumLevelHandlerTest extends TestCase
{
	public function test_it_combines_the_application_and_wrapped_handler_thresholds(): void {
		$collector = new TestHandler();
		$logger    = new Logger('records', [$collector]);
		$logger->debug('Debug.');
		$logger->warning('Warning.');
		$logger->error('Error.');
		$records = $collector->getRecords();

		$handler = new MinimumLevelHandler(new TestHandler(LogLevel::ERROR), LogLevel::WARNING);

		$this->assertFalse($handler->isHandling($records[0]));
		$this->assertFalse($handler->isHandling($records[1]));
		$this->assertTrue($handler->isHandling($records[2]));
	}

	public function test_direct_delivery_preserves_bubbling_and_excludes_lower_levels(): void {
		$collector = new TestHandler();
		$logger    = new Logger('records', [$collector]);
		$logger->debug('Debug.');
		$logger->error('Error.');
		$records = $collector->getRecords();
		$sink    = new TestHandler(LogLevel::DEBUG, false);
		$handler = new MinimumLevelHandler($sink, LogLevel::ERROR);

		$this->assertFalse($handler->handle($records[0]));
		$this->assertTrue($handler->handle($records[1]));
		$this->assertCount(1, $sink->getRecords());
	}

	public function test_batch_delivery_filters_records_without_replaying_them(): void {
		$collector = new TestHandler();
		$logger    = new Logger('records', [$collector]);
		$logger->debug('Debug.');
		$logger->error('Error.');
		$records = $collector->getRecords();
		$sink    = new TestHandler();
		$handler = new MinimumLevelHandler($sink, LogLevel::ERROR);

		$handler->handleBatch($records);
		$handler->handleBatch([$records[0]]);
		$handler->handleBatch([]);

		$this->assertCount(1, $sink->getRecords());
		$this->assertTrue($sink->hasError('Error.'));
	}

	public function test_closing_a_wrapped_composite_flushes_its_buffer(): void {
		$sink    = new TestHandler();
		$buffer  = new BufferHandler($sink);
		$handler = new MinimumLevelHandler(new GroupHandler([$buffer]), LogLevel::ERROR);
		$logger  = new Logger('buffered', [$handler]);
		$logger->debug('Debug.');
		$logger->error('Error.');

		$this->assertSame([], $sink->getRecords());

		$logger->close();
		$logger->close();

		$this->assertCount(1, $sink->getRecords());
		$this->assertTrue($sink->hasError('Error.'));
	}

	public function test_reset_reaches_the_wrapped_handler(): void {
		$sink    = new TestHandler();
		$handler = new MinimumLevelHandler(new GroupHandler([$sink]), LogLevel::ERROR);
		$logger  = new Logger('resettable', [$handler]);
		$logger->error('Error.');

		$this->assertCount(1, $sink->getRecords());

		$logger->reset();

		$this->assertSame([], $sink->getRecords());
	}

	public function test_formatter_and_processors_reach_the_wrapped_handler(): void {
		$sink      = new TestHandler();
		$handler   = new MinimumLevelHandler($sink, LogLevel::INFO);
		$formatter = new JsonFormatter();
		$processor = new PsrLogMessageProcessor();
		$handler->setFormatter($formatter);
		$handler->pushProcessor($processor);
		$logger = new Logger('formatted', [$handler]);
		$logger->info('Order {id}.', ['id' => 42]);

		$this->assertSame($formatter, $handler->getFormatter());
		$this->assertSame($formatter, $sink->getFormatter());
		$this->assertSame($processor, $handler->popProcessor());
		$this->assertTrue($sink->hasInfo('Order 42.'));
	}
}
