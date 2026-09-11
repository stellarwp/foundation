<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\WPUnit\Shutdown;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use StellarWP\Foundation\Shutdown\Contracts\ShutdownRunner;
use StellarWP\Foundation\Shutdown\ResponseFinishingRunner;
use StellarWP\Foundation\Shutdown\ShutdownProvider;
use StellarWP\Foundation\Shutdown\Task;
use StellarWP\Foundation\Tests\Support\Fixtures\Shutdown\CallbackTerminable;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;
use Symfony\Component\Process\Process;

final class ShutdownProviderTest extends WPTestCase
{
	protected function setUp(): void {
		parent::setUp();

		// Exercise only this application's shutdown work; WPTestCase restores hooks after each test.
		remove_all_actions('shutdown');
	}

	public function test_it_registers_a_singleton_runner_with_contributed_tasks(): void {
		$calls = [];

		$this->container->register(ShutdownProvider::class);
		$this->container->mergeArrayVar(ShutdownProvider::TASKS, [
			new Task(new CallbackTerminable(static function () use (&$calls): void {
				$calls[] = 'terminated';
			})),
		]);

		$runner = $this->container->get(ShutdownRunner::class);

		$this->assertInstanceOf(ResponseFinishingRunner::class, $runner);
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
		$this->container->register(ShutdownProvider::class);

		$this->container->mergeArrayVar(ShutdownProvider::TASKS, [
			new Task(new CallbackTerminable(static function () use (&$calls): void {
				$calls[] = 'terminated';
			})),
		]);

		$this->assertSame([], $calls);
		do_action('shutdown');
		do_action('shutdown');

		$this->assertSame(['terminated'], $calls);
	}

	public function test_it_does_not_resolve_the_runner_while_wordpress_is_installing(): void {
		$wasInstalling = wp_installing(true);

		try {
			$this->container->register(ShutdownProvider::class);
			$this->assertShutdownSkipsRunner();
		} finally {
			wp_installing($wasInstalling);
		}
	}

	public function test_it_checks_installation_state_after_provider_registration(): void {
		$this->container->register(ShutdownProvider::class);
		$wasInstalling = wp_installing(true);

		try {
			$this->assertShutdownSkipsRunner();
		} finally {
			wp_installing($wasInstalling);
		}
	}

	public function test_it_runs_when_installation_has_finished_before_shutdown(): void {
		$wasInstalling = wp_installing(true);
		$calls         = [];

		try {
			$this->container->register(ShutdownProvider::class);
			$this->container->mergeArrayVar(ShutdownProvider::TASKS, [
				new Task(new CallbackTerminable(static function () use (&$calls): void {
					$calls[] = 'terminated';
				})),
			]);
			wp_installing(false);
			do_action('shutdown');

			$this->assertSame(['terminated'], $calls);
		} finally {
			wp_installing($wasInstalling);
		}
	}

	public function test_uninstall_state_is_checked_before_resolving_the_runner(): void {
		foreach (['before', 'after'] as $when) {
			$process = new Process([
				PHP_BINARY,
				dirname(__DIR__, 2) . '/Support/Fixtures/Shutdown/uninstall-request.php',
				constant('ABSPATH'),
				$when,
			]);
			$process->mustRun();

			$this->assertSame('skipped', $process->getOutput(), 'Uninstall started ' . $when . ' provider registration.');
		}
	}

	/**
	 * Excluded requests must not build tasks or attempt response finishing.
	 */
	private function assertShutdownSkipsRunner(): void {
		$resolutions = 0;
		$runner      = $this->createMock(ShutdownRunner::class);
		$runner->expects($this->never())->method('terminate');
		$this->container->singleton(ShutdownRunner::class, static function () use (&$resolutions, $runner): ShutdownRunner {
			$resolutions++;

			return $runner;
		});

		do_action('shutdown');
		$this->assertSame(0, $resolutions);
	}
}
