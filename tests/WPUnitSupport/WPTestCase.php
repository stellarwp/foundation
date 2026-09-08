<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\WPUnitSupport;

use lucatume\WPBrowser\TestCase\WPTestCase as CodeceptionWPTestCase;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
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

		$this->container = (new ContainerFactory())->create(
			new ArrayConfiguration(require dirname(__DIR__) . '/config.php')
		);
	}
}
