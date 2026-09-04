<?php declare(strict_types=1);

namespace StellarWP\Foundation\Shutdown;

use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\Shutdown\Contracts\ShutdownRunner;

/**
 * Registers the default shutdown runner and task contribution point.
 */
final class ShutdownProvider extends Provider
{
	public const string TASKS = self::class . '.tasks';

	private bool $registered = false;

	public function register(): void {
		// DI52 may register the provider repeatedly, but its definitions and WordPress hook must be added only once.
		if ($this->registered) {
			return;
		}

		$this->registered = true;
		$this->container->mergeArrayVar(self::TASKS, []);

		$this->container->when(Runner::class)
			->needs('$tasks')
			->give(static fn (C $c): array => $c->get(self::TASKS));

		$this->container->singletonDecorators(ShutdownRunner::class, [
			ResponseFinishingRunner::class,
			Runner::class,
		]);

		// Installation and uninstall requests may not have the complete application state expected by shutdown tasks.
		if (defined('WP_UNINSTALL_PLUGIN') || wp_installing()) {
			return;
		}

		add_action(
			'shutdown',
			$this->container->callback(ShutdownRunner::class, 'terminate'),
			PHP_INT_MAX
		);
	}
}
