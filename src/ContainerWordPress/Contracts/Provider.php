<?php declare(strict_types=1);

namespace StellarWP\Foundation\ContainerWordPress\Contracts;

use Adbar\Dot;
use StellarWP\Foundation\Container\Contracts\Container as FoundationContainer;
use StellarWP\Foundation\Container\Contracts\Provider as FoundationProvider;
use StellarWP\Foundation\ContainerWordPress\ContainerAdapter;

/**
 * Providers should extend this abstract in order to have
 * access to the container instance to register their bindings.
 */
abstract class Provider extends FoundationProvider
{
	public function __construct(
		/** @var Container|ContainerAdapter $container */
		protected readonly FoundationContainer $container,
		/** @var Dot<array-key, mixed> */
		protected readonly Dot $config
	) {
	}
}
