<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Shutdown;

use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\Container\Exceptions\ContainerException;
use StellarWP\Foundation\Shutdown\ShutdownProvider;
use StellarWP\Foundation\Shutdown\Task;

/**
 * Shares the request buffer and contributes its cache writer lazily.
 */
final class Product_Cache_Provider extends Provider
{
	private bool $registered = false;

	/**
	 * Register the shared buffer, cache settings, and shutdown task.
	 *
	 * @throws ContainerException When service bindings cannot be registered.
	 */
	public function register(): void {
		if ($this->registered) {
			return;
		}

		$this->registered = true;
		$this->container->singleton(Product_Cache_Buffer::class);

		$this->container->when(Product_Cache_Writer::class)
			->needs('$cache_key')
			->give($this->config->get('product_cache.key'));

		$this->container->when(Product_Cache_Writer::class)
			->needs('$ttl')
			->give($this->config->get('product_cache.ttl'));

		$this->container->mergeArrayVar(
			ShutdownProvider::TASKS,
			static fn (C $c): array => [new Task($c->get(Product_Cache_Writer::class), 100)]
		);
	}
}
