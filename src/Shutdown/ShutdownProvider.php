<?php declare(strict_types=1);

namespace StellarWP\Foundation\Shutdown;

use lucatume\DI52\Container;
use StellarWP\Foundation\Container\Contracts\Provider;

/**
 * Registers the default shutdown runner and task contribution point.
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

		$this->container->singleton(ShutdownRunner::class);

		add_action(
			'shutdown',
			$this->container->callback(ShutdownRunner::class, 'terminate'),
			PHP_INT_MAX
		);
	}
}
