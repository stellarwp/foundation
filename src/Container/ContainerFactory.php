<?php declare(strict_types=1);

namespace StellarWP\Foundation\Container;

use lucatume\DI52\Container as DI52Container;
use StellarWP\ContainerContract\ContainerInterface;
use StellarWP\Foundation\Container\Contracts\Configuration;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Container\Contracts\Resolver;

/**
 * Creates Foundation's default container without exposing its backend.
 */
final class ContainerFactory
{
	/**
	 * Create a container with its core contracts and configuration registered.
	 */
	public function create(Configuration $configuration): Container {
		$container = new ContainerAdapter(new DI52Container());

		$container->bind(Container::class, $container);
		$container->bind(Resolver::class, $container);
		$container->bind(ContainerInterface::class, $container);
		$container->singleton(Configuration::class, $configuration);

		return $container;
	}
}
