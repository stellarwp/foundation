<?php declare(strict_types=1);

namespace StellarWP\Foundation\LockRedis;

use InvalidArgumentException;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\Lock\Contracts\Clock;
use StellarWP\Foundation\Lock\SystemClock;

/**
 * Registers Redis lock services without choosing a client or global lock strategy.
 */
final class LockRedisProvider extends Provider
{
	private const string PREFIX = self::class . '.prefix';

	/**
	 * Register the Redis lock policy and its shared implementation services.
	 *
	 * @throws InvalidArgumentException When the required Redis lock prefix is not configured.
	 */
	public function register(): void {
		$this->registerConfiguration();
		$this->registerClock();
		$this->registerLock();
	}

	/**
	 * Validate and register the prefix used to isolate this application's locks.
	 *
	 * @throws InvalidArgumentException When the required Redis lock prefix is not configured.
	 */
	private function registerConfiguration(): void {
		$prefix = $this->config->get('lock.redis.prefix');

		if (! is_string($prefix) || trim($prefix) === '') {
			throw new InvalidArgumentException('The lock.redis.prefix configuration value must be a non-empty string.');
		}

		$this->container->singleton(self::PREFIX, $prefix);
	}

	/**
	 * Register the system clock used to calculate lock expirations.
	 */
	private function registerClock(): void {
		$this->container->singleton(SystemClock::class);
	}

	/**
	 * Register the Redis lock while leaving its connection to the application.
	 */
	private function registerLock(): void {
		$this->container->when(RedisLock::class)
			->needs(Clock::class)
			->give(static fn (C $c): SystemClock => $c->get(SystemClock::class));

		$this->container->when(RedisLock::class)
			->needs('$prefix')
			->give(static fn (C $c): string => $c->get(self::PREFIX));

		$this->container->singleton(RedisLock::class);
	}
}
