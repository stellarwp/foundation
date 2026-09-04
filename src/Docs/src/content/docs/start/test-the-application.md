---
title: Test the Application
description: Test provider configuration independently or reboot the complete WordPress application with controlled environment values.
sidebar:
  order: 7
---

Configuration-dependent tests need different setup depending on the boundary under test. Construct a fresh container when testing one provider. Start a new PHP process when the complete WordPress application must read different environment values during bootstrap.

## Test provider configuration

Focused provider tests should pass configuration values directly to a new `ArrayConfiguration`. Add a helper to the project's base test case so every call creates a container with an independent configuration snapshot.

Create `tests/TestCase.php`:

```php title="TestCase.php"
<?php declare(strict_types=1);

namespace Plugin\Tests;

use lucatume\WPBrowser\TestCase\WPTestCase;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Container\Contracts\Container;

abstract class TestCase extends WPTestCase {

	/**
	 * Create a new container using the supplied configuration values.
	 *
	 * @param array<string, mixed> $configuration
	 */
	protected function create_container( array $configuration = [] ): Container {
		return ( new ContainerFactory() )->create(
			new ArrayConfiguration( $configuration )
		);
	}
}
```

Use the helper to create a fresh container for each scenario so registered bindings and resolved singletons cannot retain values from another test:

```php title="Catalog_Provider_Test.php"
<?php declare(strict_types=1);

namespace Plugin\Tests\WPUnit\Catalog;

use Plugin\Catalog\Catalog_Provider;
use Plugin\Catalog\Catalog_Synchronizer;
use Plugin\Tests\TestCase;

final class Catalog_Provider_Test extends TestCase {

	public function test_it_disables_catalog_synchronization(): void {
		$container = $this->create_container( [
			'catalog' => [
				'sync_enabled' => false,
			],
		] );
		$container->register( Catalog_Provider::class );

		$synchronizer = $container->get( Catalog_Synchronizer::class );

		$this->assertFalse( $synchronizer->is_enabled() );
	}
}
```

This approach tests provider behavior without relying on the application's static `App` singleton or changing process-level environment values. The shared test case loads WordPress, so providers can register hooks and use WordPress APIs while still receiving an independent container.

## Test application configuration

Tests that exercise the complete application need a new PHP process. wp-browser's `WPLoader` loads WordPress and active plugins before PHPUnit runs a test class's setup hooks, so changing `$_ENV` in an ordinary `setUp()` method does not reconfigure an `App` singleton that already exists.

Enable wp-browser's isolation extension in the root `codeception.dist.yml`. The extension starts another Codeception process for isolated tests so `WPLoader` can boot WordPress and the application from a clean PHP process:

```yaml title="codeception.dist.yml"
extensions:
  enabled:
    - lucatume\WPBrowser\Extension\IsolationSupport
```

Set the environment in the standard `setUpBeforeClass()` hook and mark the class with `RunTestsInSeparateProcesses`. Although `WPLoader` has already booted the application in the outer test process, wp-browser launches the isolated Codeception process when the test method begins. The child inherits the environment before its own `WPLoader` initializes, so it constructs a new application with the configured value.

Synchronize the process environment with `$_ENV` and `$_SERVER` so Symfony Process passes a newly introduced variable to the child:

```php title="Catalog_Application_Test.php"
<?php declare(strict_types=1);

namespace Plugin\Tests\WPUnit\Catalog;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Plugin\Catalog\Catalog_Synchronizer;
use Plugin\Tests\TestCase;

use function Plugin\plugin;

#[RunTestsInSeparateProcesses]
final class Catalog_Application_Test extends TestCase {

	private const string ENV_SYNC_ENABLED = 'CATALOG_SYNC_ENABLED';

	private static string|false $original_sync_enabled;

	public static function setUpBeforeClass(): void {
		self::$original_sync_enabled = getenv( self::ENV_SYNC_ENABLED );
		self::set_sync_enabled_environment( 'true' );

		parent::setUpBeforeClass();
	}

	public static function tearDownAfterClass(): void {
		if ( self::$original_sync_enabled === false ) {
			putenv( self::ENV_SYNC_ENABLED );
			unset( $_ENV[ self::ENV_SYNC_ENABLED ], $_SERVER[ self::ENV_SYNC_ENABLED ] );
		} else {
			self::set_sync_enabled_environment( self::$original_sync_enabled );
		}

		parent::tearDownAfterClass();
	}

	public function test_it_boots_with_catalog_synchronization_enabled(): void {
		$synchronizer = plugin()->container()->get( Catalog_Synchronizer::class );

		$this->assertTrue( $synchronizer->is_enabled() );
	}

	private static function set_sync_enabled_environment( string $value ): void {
		putenv( self::ENV_SYNC_ENABLED . '=' . $value );
		$_ENV[ self::ENV_SYNC_ENABLED ]    = $value;
		$_SERVER[ self::ENV_SYNC_ENABLED ] = $value;
	}
}
```

Use one isolated test class for each application configuration. The underscored `_setUpBeforeClass()` hook remains available as a Codeception compatibility API, but new tests should use PHPUnit's standard `setUpBeforeClass()` method.
