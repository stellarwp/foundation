<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\WPUnit\Shutdown;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use StellarWP\Foundation\Shutdown\Contracts\ShutdownRunner as ShutdownRunnerContract;
use StellarWP\Foundation\Shutdown\ResponseFinishingRunner;
use StellarWP\Foundation\Shutdown\ShutdownProvider;
use StellarWP\Foundation\Shutdown\ShutdownTask;
use StellarWP\Foundation\Tests\Support\Fixtures\Shutdown\CallbackTerminable;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;

final class ShutdownProviderTest extends WPTestCase
{
	protected function tearDown(): void {
		if ($this->container->has(ShutdownRunnerContract::class)) {
			remove_action(
				'shutdown',
				$this->container->callback(ShutdownRunnerContract::class, 'terminate'),
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

		$runner = $this->container->get(ShutdownRunnerContract::class);

		$this->assertInstanceOf(ResponseFinishingRunner::class, $runner);
		$this->assertSame($runner, $this->container->get(ShutdownRunnerContract::class));

		$runner->terminate();

		$this->assertSame(['terminated'], $calls);
	}

	public function test_duplicate_provider_registration_does_not_replace_the_runner(): void {
		$this->container->register(ShutdownProvider::class);
		$runner = $this->container->get(ShutdownRunnerContract::class);

		$this->container->register(ShutdownProvider::class);

		$this->assertSame($runner, $this->container->get(ShutdownRunnerContract::class));
	}

	public function test_it_injects_a_registered_psr_logger(): void {
		$handler = new TestHandler();

		$this->container->singleton(LoggerInterface::class, new Logger('shutdown', [$handler]));
		$this->container->register(ShutdownProvider::class);

		$this->container->get(ShutdownRunnerContract::class)->terminate();

		$this->assertTrue($handler->hasDebugThatMatches('/Running shutdown tasks\./'));
	}

	public function test_it_runs_contributed_tasks_on_wordpress_shutdown(): void {
		$calls = [];

		$this->container->register(ShutdownProvider::class);
		$callback = $this->container->callback(ShutdownRunnerContract::class, 'terminate');

		$this->container->mergeArrayVar(ShutdownProvider::TASKS, [
			new ShutdownTask(new CallbackTerminable(static function () use (&$calls): void {
				$calls[] = 'terminated';
			})),
		]);

		$this->assertSame(PHP_INT_MAX, has_action('shutdown', $callback));

		$callback();

		$this->assertSame(['terminated'], $calls);
	}

	public function test_it_does_not_register_the_shutdown_hook_while_wordpress_is_installing(): void {
		$wasInstalling = wp_installing(true);

		try {
			$this->container->register(ShutdownProvider::class);
			$callback = $this->container->callback(ShutdownRunnerContract::class, 'terminate');

			$this->assertFalse(has_action('shutdown', $callback));
			$this->assertInstanceOf(
				ResponseFinishingRunner::class,
				$this->container->get(ShutdownRunnerContract::class)
			);
		} finally {
			wp_installing($wasInstalling);
		}
	}
}
