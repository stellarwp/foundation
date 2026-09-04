---
title: Configure the Container
description: Construct the Foundation container and make application configuration available to providers.
sidebar:
  order: 3
---

Foundation adapts its default container backend behind Foundation-owned contracts. Create one container during application bootstrap and use that instance for every provider.

## Create the shared container

Use `ContainerFactory` in the application's composition root. It creates the default backend and registers the `Container`, `Resolver`, and `Configuration` contracts. Feature classes should receive the services they need rather than creating another container.

```php title="bootstrap.php"
<?php declare(strict_types=1);

use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;

$config    = new ArrayConfiguration( require __DIR__ . '/config.php' );
$container = ( new ContainerFactory() )->create( $config );
```

The `Container` contract allows providers to register services through the shared container. The narrower `Resolver` contract is used by services that only need to resolve class-based collaborators, such as Pipeline. `Configuration` makes the same read-only configuration snapshot available to every Foundation provider without exposing the underlying configuration library.

Use `get()` to read a dotted key, `has()` to distinguish a missing key from an existing key whose value is `null`, and `all()` when the complete configuration array is required. Assemble or merge configuration values before constructing `ArrayConfiguration`; providers should not mutate configuration while the application is registering or serving a request.

## Map environment values in config.php

Keep environment access at the configuration boundary rather than reading `$_ENV` throughout application services. Map values in the root `config.php`:

```php title="config.php"
<?php declare(strict_types=1);

return [
	'foundation' => [
		'prefix' => $_ENV['FOUNDATION_PREFIX'] ?? 'your-plugin',
	],
	'catalog' => [
		'sync_enabled' => filter_var(
			$_ENV['CATALOG_SYNC_ENABLED'] ?? false,
			FILTER_VALIDATE_BOOLEAN
		),
	],
	'log' => [
		'channel' => $_ENV['APP_LOG_CHANNEL'] ?? 'null',
		'level'   => $_ENV['APP_LOG_LEVEL'] ?? 'debug',
	],
];
```

Environment variables are commonly strings. Use `filter_var()` with `FILTER_VALIDATE_BOOLEAN` for boolean settings so values such as `false`, `off`, and `0` are not treated as truthy merely because they are non-empty strings.

Every provider can read nested values through its inherited `$this->config` property:

```php
$channel = $this->config->get( 'log.channel' );
```

The default value is used only when a key is absent. A configured `null` value remains `null`. Treat configuration as a stable snapshot for the lifetime of the application container.

Pass resolved configuration into service constructors through container bindings. Application services should not read the environment or the configuration object directly.

## Test configuration-dependent behavior

Choose the test setup according to whether the behavior requires the complete WordPress application to boot.

### Test a provider with a fresh container

Focused provider tests should pass configuration values directly to a new `ArrayConfiguration`. Create a new container for each scenario so registered bindings and resolved singletons cannot retain values from another test:

```php title="Catalog_Provider_Test.php"
<?php declare(strict_types=1);

namespace Plugin\Tests\Feature\Catalog;

use PHPUnit\Framework\TestCase;
use Plugin\Catalog\Catalog_Provider;
use Plugin\Catalog\Catalog_Synchronizer;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;

final class Catalog_Provider_Test extends TestCase {

	public function test_it_disables_catalog_synchronization(): void {
		$config = new ArrayConfiguration( [
			'catalog' => [
				'sync_enabled' => false,
			],
		] );
		$container = ( new ContainerFactory() )->create( $config );
		$container->register( Catalog_Provider::class );

		$synchronizer = $container->get( Catalog_Synchronizer::class );

		$this->assertFalse( $synchronizer->is_enabled() );
	}
}
```

This approach tests provider behavior without relying on the application's static `App` singleton or changing process-level environment values.

### Reboot a WordPress application with different configuration

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
use Plugin\Tests\WPUnitSupport\WPTestCase;

use function Plugin\plugin;

#[RunTestsInSeparateProcesses]
final class Catalog_Application_Test extends WPTestCase {

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

## Load an optional .env file

The container package includes `vlucas/phpdotenv`. A non-WordPress application can load a local environment file before requiring `config.php`:

```php
use Dotenv\Dotenv;

if ( is_file( __DIR__ . '/.env' ) ) {
	Dotenv::createImmutable( __DIR__ )->load();
}
```

WordPress applications can map deployment values into `$_ENV` before the plugin constructs its container. Keep local environment files out of production archives.

## Continue

[Bootstrap a WordPress plugin](/start/bootstrap-wordpress-plugin/) with one application object that owns the container, configuration, and provider order.
