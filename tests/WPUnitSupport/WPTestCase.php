<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\WPUnitSupport;

use lucatume\WPBrowser\TestCase\WPTestCase as CodeceptionWPTestCase;

/**
 * Base test case for WordPress integration tests.
 *
 * Extend this instead of Codeception's test case directly so Foundation can
 * centralize WordPress setup, teardown, and helper behavior as the suite grows.
 */
abstract class WPTestCase extends CodeceptionWPTestCase
{
}
