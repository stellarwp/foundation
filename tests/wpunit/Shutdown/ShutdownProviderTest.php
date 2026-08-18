<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\WPUnit\Shutdown;

use Adbar\Dot;
use lucatume\DI52\Container as DI52Container;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use StellarWP\Foundation\Container\ContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Shutdown\ShutdownProvider;
use StellarWP\Foundation\Shutdown\ShutdownRunner;
use StellarWP\Foundation\Shutdown\ShutdownTask;
use StellarWP\Foundation\Tests\Support\Fixtures\Shutdown\CallbackTerminable;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;

final class ShutdownProviderTest extends WPTestCase
{
	private ContainerAdapter $container;

	protected function setUp(): void {
		parent::setUp();

		$this->container = new ContainerAdapter(new DI52Container());
		$this->container->bind(Container::class, $this->container);
		$this->container->singleton(Dot::class, new Dot());
	}

	protected function tearDown(): void {
		if ($this->container->has(ShutdownRunner::class)) {
			remove_action(
				'shutdown',
				$this->container->callback(ShutdownRunner::class, 'terminate'),
				PHP_INT_MAX
			);
		}

		parent::tearDown();
	}

	public function test_it_registers_a_singleton_runner_with_contributed_tasks(): void {
		$calls = [];

		$this->container->register(ShutdownProvider::class);
		$this->container->mergeArrayVar(ShutdownProvider::TASKS, [
			new ShutdownTask(new CallbackTerminable(static function () use (&$calls): void {
				$calls[] = 'terminated';
			})),
		]);

		$runner = $this->container->get(ShutdownRunner::class);

		$this->assertSame($runner, $this->container->get(ShutdownRunner::class));

		$runner->terminate();

		$this->assertSame(['terminated'], $calls);
	}

	public function test_duplicate_provider_registration_does_not_replace_the_runner(): void {
		$this->container->register(ShutdownProvider::class);
		$runner = $this->container->get(ShutdownRunner::class);

		$this->container->register(ShutdownProvider::class);

		$this->assertSame($runner, $this->container->get(ShutdownRunner::class));
	}

	public function test_it_injects_a_registered_psr_logger(): void {
		$handler = new TestHandler();

		$this->container->singleton(LoggerInterface::class, new Logger('shutdown', [$handler]));
		$this->container->register(ShutdownProvider::class);

		$this->container->get(ShutdownRunner::class)->terminate();

		$this->assertTrue($handler->hasDebugThatMatches('/Running shutdown tasks\./'));
	}

	public function test_it_runs_contributed_tasks_on_wordpress_shutdown(): void {
		$calls = [];

		$this->container->register(ShutdownProvider::class);
		$callback = $this->container->callback(ShutdownRunner::class, 'terminate');

		$this->container->mergeArrayVar(ShutdownProvider::TASKS, [
			new ShutdownTask(new CallbackTerminable(static function () use (&$calls): void {
				$calls[] = 'terminated';
			})),
		]);

		$this->assertSame(PHP_INT_MAX, has_action('shutdown', $callback));

		$callback();

		$this->assertSame(['terminated'], $calls);
	}
}
