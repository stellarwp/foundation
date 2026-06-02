<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Container;

use Adbar\Dot;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Container\Contracts\Provider;

final class TestProvider extends Provider
{
	public function __construct(Container $container) {
		parent::__construct($container, new Dot());
	}

	public function register(): void {
	}
}
