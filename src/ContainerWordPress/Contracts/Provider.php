<?php declare(strict_types=1);

namespace StellarWP\Foundation\ContainerWordPress\Contracts;

use Adbar\Dot;
use StellarWP\Foundation\Container\Contracts\Providable;
use StellarWP\Foundation\ContainerWordPress\ContainerAdapter;

/**
 * Base provider for WordPress projects using the WordPress-aware container.
 *
 * Extend this provider when a service provider needs access to WordPress-specific
 * container helpers such as hook-aware provider registration.
 */
abstract class Provider implements Providable
{
	/**
	 * Whether this service provider will be a deferred one or not.
	 */
	protected bool $deferred = false;

	/**
	 * @param Dot<array-key, mixed> $config
	 */
	public function __construct(
		/** @var Container|ContainerAdapter $container */
		protected readonly Container $container,
		protected readonly Dot $config
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function isDeferred(): bool {
		return $this->deferred;
	}

	/**
	 * {@inheritDoc}
	 */
	public function provides(): array {
		return [];
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot(): void {
	}
}
