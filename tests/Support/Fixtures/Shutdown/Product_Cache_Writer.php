<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Shutdown;

use StellarWP\Foundation\Shutdown\Contracts\Terminable;

/**
 * Writes the request's buffered product snapshot to WordPress at shutdown.
 */
final readonly class Product_Cache_Writer implements Terminable
{
	/**
	 * Receive the shared buffer and application cache settings.
	 */
	public function __construct(
		private Product_Cache_Buffer $buffer,
		private string $cache_key,
		private int $ttl
	) {
	}

	/**
	 * Persist the product snapshot when it changed during the request.
	 */
	public function terminate(): void {
		if (! $this->buffer->has_changes()) {
			return;
		}

		set_transient($this->cache_key, $this->buffer->snapshot(), $this->ttl);
	}
}
