<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\ContainerWordPress;

use Adbar\Dot;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Container\Contracts\Provider;

/**
 * Second registerable provider used to observe dependant registration ordering.
 */
final class SecondProvider extends Provider
{
	public function __construct(Container $container) {
		parent::__construct($container, new Dot());
	}

	public function register(): void {
	}
}
