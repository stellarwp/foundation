<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Container;

use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Container\Contracts\Providable;

/**
 * Represents an application's own registration class with constructor-injected services.
 */
final readonly class IndependentProvider implements Providable
{
	public function __construct(
		private Container $container,
		private CallbackListener $listener
	) {
	}

	public function register(): void {
		$this->listener->listen();
		$this->container->singleton('application.listener', $this->listener);
	}
}
