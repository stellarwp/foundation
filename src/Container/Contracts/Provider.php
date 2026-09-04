<?php declare(strict_types=1);

namespace StellarWP\Foundation\Container\Contracts;

/**
 * Providers should extend this abstract in order to have
 * access to the container instance to register their bindings.
 */
abstract class Provider implements Providable
{
	public function __construct(
		protected readonly Container $container
	) {
	}
}
