---
title: Bootstrap a WordPress Plugin
description: Use an application composition root to configure Foundation and register plugin features in one place.
sidebar:
  order: 4
---

A WordPress plugin needs one predictable place to construct its container, load configuration, and register service providers. Keep that work in a small application composition root so feature classes receive fully configured dependencies.

## Create the application configuration

The root `config.php` maps environment values into the structure providers consume:

```php title="config.php"
<?php declare(strict_types=1);

return [
	'foundation' => [
		'prefix' => $_ENV['FOUNDATION_PREFIX'] ?? 'your-plugin',
	],
];
```

## Compose the application in App

Create `src/App.php`. The application object binds shared values before registering providers, and its provider list makes startup order visible from one file:

```php title="App.php"
<?php declare(strict_types=1);

namespace Plugin;

use RuntimeException;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Container\Contracts\Provider;

final class App {

	public const string PLUGIN_FILE = 'your_plugin.plugin_file';
	public const string PLUGIN_DIR  = 'your_plugin.plugin_dir';

	/** @var list<class-string<Provider>> */
	private const array PROVIDERS = [];

	private static self $instance;

	private function __construct(
		private readonly string $plugin_file,
		private readonly Container $container
	) {
		$this->configure_container();
		$this->register_providers();
	}

	public static function instance(
		string $plugin_file,
		Container $container
	): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self( $plugin_file, $container );
		}

		return self::$instance;
	}

	/**
	 * Prevent cloning the application singleton.
	 */
	private function __clone(): void {
	}

	/**
	 * Prevent serializing the application singleton.
	 *
	 * @throws RuntimeException Always, because the application must be created through instance().
	 */
	public function __sleep(): array {
		throw new RuntimeException( 'The application singleton cannot be serialized.' );
	}

	/**
	 * Prevent restoring the application singleton without running its constructor.
	 *
	 * @throws RuntimeException Always, because the application must be created through instance().
	 */
	public function __wakeup(): void {
		throw new RuntimeException( 'The application singleton cannot be unserialized.' );
	}

	public function container(): Container {
		return $this->container;
	}

	private function configure_container(): void {
		$this->container->singleton( self::PLUGIN_FILE, $this->plugin_file );
		$this->container->singleton( self::PLUGIN_DIR, plugin_dir_path( $this->plugin_file ) );
	}

	private function register_providers(): void {
		foreach ( self::PROVIDERS as $provider ) {
			$this->container->register( $provider );
		}
	}
}
```

Add infrastructure providers and top-level feature providers directly to `PROVIDERS` as application features are introduced. Keep cross-feature dependencies and their registration order visible in this composition root.

A large feature may expose one composition provider that registers its own internal providers. That provider should do only that: it must not also register service definitions, configuration, or hooks.

## Create the application helper

Create `src/functions.php`. The helper supplies the application dependencies on first use, and `App::instance()` returns the same application for the remainder of the request:

```php title="functions.php"
<?php declare(strict_types=1);

namespace Plugin;

use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;

function plugin(): App {
	static $app;

	if ( isset( $app ) ) {
		return $app;
	}

	$config    = new ArrayConfiguration( require dirname( __DIR__ ) . '/config.php' );
	$container = ( new ContainerFactory() )->create( $config );

	$app = App::instance(
		dirname( __DIR__ ) . '/plugin.php',
		$container
	);

	return $app;
}
```

Calls elsewhere in the plugin can use `plugin()->container()` when WordPress invokes a callback that cannot receive constructor dependencies, such as activation and deactivation hooks. Application services should continue to use constructor injection.

## Autoload the application with Composer

In the root `composer.json`, map the plugin namespace to `src/` and autoload the application helper:

```json title="composer.json"
{
  "autoload": {
    "psr-4": {
      "Plugin\\\\": "src/"
    },
    "files": [
      "src/functions.php"
    ]
  },
  "require": {
    "php": ">=8.3",
    "stellarwp/foundation-container": "^2.0"
  }
}
```

Regenerate Composer's autoloader after changing the mapping:

```shell
composer dump-autoload
```

## Start the application from the plugin entrypoint

The root `plugin.php` can now load Composer and defer application startup to an appropriate WordPress hook:

```php title="plugin.php"
<?php declare(strict_types=1);

/**
 * Plugin Name: Your Plugin
 * Requires PHP: 8.3
 */

defined( 'ABSPATH' ) || exit;

use function Plugin\plugin;

require_once __DIR__ . '/vendor/autoload.php';

add_action( 'plugins_loaded', static function (): void {
	plugin();
}, 0, 0 );
```

Choose the hook and priority according to when the plugin's integrations must become available. The entrypoint should not contain feature bindings or business behavior.

With this structure, WordPress starts one application, the application prepares one container, and providers compose each feature without spreading bootstrap logic throughout the plugin.
