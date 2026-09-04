<?php declare(strict_types=1);

namespace StellarWP\Foundation\Container\Configuration;

use Adbar\Dot;
use StellarWP\Foundation\Container\Contracts\Configuration;

/**
 * Reads dotted configuration keys from an application configuration array.
 */
final readonly class ArrayConfiguration implements Configuration
{
	/** @var Dot<string, mixed> */
	private Dot $configuration;

	/**
	 * Create configuration backed by the supplied application values.
	 *
	 * @param array<string, mixed> $configuration
	 */
	public function __construct(array $configuration = []) {
		$this->configuration = new Dot($configuration);
	}

	/**
	 * {@inheritDoc}
	 */
	public function all(): array {
		return $this->configuration->all();
	}

	/**
	 * {@inheritDoc}
	 */
	public function has(string $key): bool {
		return $this->configuration->has($key);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get(string $key, mixed $default = null): mixed {
		return $this->configuration->get($key, $default);
	}
}
