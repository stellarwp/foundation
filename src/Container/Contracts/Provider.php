<?php declare(strict_types=1);

namespace StellarWP\Foundation\Container\Contracts;

use Adbar\Dot;
use StellarWP\Foundation\Container\ContainerAdapter;

/**
 * Providers should extend this abstract in order to have
 * access to the container instance to register their bindings.
 */
abstract class Provider implements Providable
{
	/**
	 * Whether this service provider will be a deferred one or not.
	 */
	protected bool $deferred = false;

	public function __construct(
		/** @var Container|ContainerAdapter $container */
		protected readonly Container $container,
		/** @var Dot<array-key, mixed> */
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
