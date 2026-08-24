<?php declare(strict_types=1);

namespace StellarWP\Foundation\Shutdown;

use lucatume\DI52\Container;
use StellarWP\Foundation\Container\ContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Shutdown\Contracts\ShutdownRunner as ShutdownRunnerContract;

/**
 * Registers the default shutdown runner and task contribution point.
 *
 * @property-read ContainerAdapter $container
 */
final class ShutdownProvider extends Provider
{
	public const string TASKS       = self::class . '.tasks';
	private const string REGISTERED = self::class . '.registered';

	public function register(): void {
		if ($this->container->has(self::REGISTERED)) {
			return;
		}

		$this->container->singleton(self::REGISTERED, true);

		$this->container->when(ShutdownRunner::class)
			->needs('$tasks')
			->give(static fn (Container $container): array => $container->getVar(self::TASKS, []));

		$this->container->singletonDecorators(ShutdownRunnerContract::class, [
			ResponseFinishingRunner::class,
			ShutdownRunner::class,
		]);

		// Installation and uninstall requests may not have the complete application state expected by shutdown tasks.
		if (defined('WP_UNINSTALL_PLUGIN') || wp_installing()) {
			return;
		}

		add_action(
			'shutdown',
			$this->container->callback(ShutdownRunnerContract::class, 'terminate'),
			PHP_INT_MAX
		);
	}
}
