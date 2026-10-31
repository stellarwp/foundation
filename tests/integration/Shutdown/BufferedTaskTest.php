<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Shutdown;

use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Shutdown\ShutdownProvider;
use StellarWP\Foundation\Tests\Support\Fixtures\Shutdown\Product_Cache_Buffer;
use StellarWP\Foundation\Tests\Support\Fixtures\Shutdown\Product_Cache_Provider;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;

final class BufferedTaskTest extends WPTestCase
{
	public function test_it_writes_the_request_buffer_once_at_shutdown(): void {
		// WPTestCase restores the original hooks after this test.
		remove_all_actions('shutdown');
		$cacheKey        = 'foundation_test_shutdown_products';
		$this->container = (new ContainerFactory())->create(new ArrayConfiguration([
			'product_cache' => ['key' => $cacheKey, 'ttl' => 300],
		]));
		$this->container->register(ShutdownProvider::class);

		try {
			$this->container->register(Product_Cache_Provider::class);
			$buffer   = $this->container->get(Product_Cache_Buffer::class);
			$products = [['id' => 42, 'name' => 'Collected during the request']];
			$buffer->replace($products);

			$this->assertFalse(get_transient($cacheKey));
			do_action('shutdown');

			$this->assertSame($products, get_transient($cacheKey));

			$buffer->replace([['id' => 99, 'name' => 'Added after shutdown']]);
			do_action('shutdown');

			$this->assertSame($products, get_transient($cacheKey));
		} finally {
			delete_transient($cacheKey);
		}
	}
}
