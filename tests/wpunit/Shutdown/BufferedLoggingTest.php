<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\WPUnit\Shutdown;

use Monolog\Handler\BufferHandler;
use Monolog\Handler\TestHandler;
use Psr\Log\LoggerInterface;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Log\LogProvider;
use StellarWP\Foundation\Shutdown\ShutdownProvider;
use StellarWP\Foundation\Shutdown\Task;
use StellarWP\Foundation\Tests\Support\Fixtures\Shutdown\BufferedLogFlush;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;

final class BufferedLoggingTest extends WPTestCase
{
	public function test_shared_logger_delivers_buffered_records_once_at_wordpress_shutdown(): void {
		remove_all_actions('shutdown');

		$configuration = new ArrayConfiguration([
			'log' => [
				'channel'  => 'buffered',
				'level'    => 'info',
				'channels' => [
					'buffered' => [
						'handler' => BufferHandler::class,
					],
				],
			],
		]);
		$factory   = new ContainerFactory();
		$container = $factory->create($configuration);
		$sink      = new TestHandler();
		$buffer    = new BufferHandler($sink, 100);
		$flush     = new BufferedLogFlush($buffer);

		$container->singleton(BufferHandler::class, $buffer);
		$container->register(LogProvider::class);
		$container->register(ShutdownProvider::class);
		$container->mergeArrayVar(ShutdownProvider::TASKS, [
			new Task($flush, 100),
		]);

		$logger = $container->get(LoggerInterface::class);
		$logger->info('Order {order_id} paid.', [
			'order_id' => 42,
		]);
		$logger->warning('Delivery delayed.');

		$this->assertSame($logger, $container->get(LoggerInterface::class));
		$this->assertSame([], $sink->getRecords());

		do_action('shutdown');
		do_action('shutdown');

		$this->assertCount(2, $sink->getRecords());
		$this->assertTrue($sink->hasInfo('Order 42 paid.'));
		$this->assertTrue($sink->hasWarning('Delivery delayed.'));
	}
}
