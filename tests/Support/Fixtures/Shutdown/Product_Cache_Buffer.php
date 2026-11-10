<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Shutdown;

/**
 * Collects a product snapshot during a request.
 */
final class Product_Cache_Buffer
{
	/**
	 * @var list<array{id: int, name: string}>
	 */
	private array $products = [];

	private bool $changed = false;

	/**
	 * Replace the snapshot to be written at shutdown.
	 *
	 * @param list<array{id: int, name: string}> $products
	 */
	public function replace(array $products): void {
		$this->products = $products;
		$this->changed  = true;
	}

	/**
	 * Determine whether the request has supplied a new snapshot.
	 */
	public function has_changes(): bool {
		return $this->changed;
	}

	/**
	 * Return the collected product snapshot.
	 *
	 * @return list<array{id: int, name: string}>
	 */
	public function snapshot(): array {
		return $this->products;
	}
}
