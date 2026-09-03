<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\WPUnitSupport;

use Adbar\Dot;
use lucatume\WPBrowser\TestCase\WPTestCase as CodeceptionWPTestCase;
use StellarWP\ContainerContract\ContainerInterface;
use StellarWP\Foundation\Container\ContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Container;

/**
 * Base test case for WordPress integration tests.
 *
 * Extend this instead of Codeception's test case directly so Foundation can
 * centralize WordPress setup, teardown, and helper behavior as the suite grows.
 */
abstract class WPTestCase extends CodeceptionWPTestCase
{
	protected Container $container;

	protected function setUp(): void {
		parent::setUp();

		$this->container = new ContainerAdapter(new \lucatume\DI52\Container());
		$this->container->bind(Container::class, $this->container);
		$this->container->bind(ContainerInterface::class, $this->container);
		$this->container->singleton(Dot::class, new Dot(require dirname(__DIR__) . '/config.php'));
	}
}
