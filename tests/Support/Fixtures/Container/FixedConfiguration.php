<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Container;

use StellarWP\Foundation\Container\Contracts\Configuration;

final readonly class FixedConfiguration implements Configuration
{
	/**
	 * @param array<string, mixed> $values
	 */
	public function __construct(
		private array $values
	) {
	}

	public function get(string $key, mixed $default = null): mixed {
		return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
	}
}
