<?php declare(strict_types=1);

namespace StellarWP\Foundation\Container\Contracts;

use StellarWP\Foundation\Container\Exceptions\ContainerException;
use StellarWP\Foundation\Container\Exceptions\NotFoundException;

/**
 * Provides feature registration access to the shared container and configuration.
 */
abstract class Provider
{
	protected readonly Configuration $config;

	/**
	 * Provide the shared container and resolve the application configuration used during registration.
	 *
	 * @throws ContainerException When the configuration service cannot be resolved.
	 * @throws NotFoundException  When the configuration service has not been registered.
	 */
	final public function __construct(
		protected readonly Container $container
	) {
		$this->config = $this->container->get(Configuration::class);
	}

	/**
	 * Register this feature's bindings and application hooks.
	 */
	abstract public function register(): void;
}
