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

The `Container` contract allows providers to register services through the shared container. The narrower `Resolver` contract is used by services that only need to resolve class-based collaborators, such as Pipeline. `Configuration` makes the same read-only configuration snapshot available to every configured Foundation provider without exposing the underlying configuration library.

## Map environment values in config.php

Keep environment access at the configuration boundary rather than reading `$_ENV` throughout application services. Map values in the root `config.php`:

```php title="config.php"
<?php declare(strict_types=1);

return [
	'foundation' => [
		'prefix' => $_ENV['FOUNDATION_PREFIX'] ?? 'your-plugin',
	],
	'log' => [
		'channel' => $_ENV['APP_LOG_CHANNEL'] ?? 'null',
		'level'   => $_ENV['APP_LOG_LEVEL'] ?? 'debug',
	],
];
```

Providers that extend `ConfiguredProvider` read nested values through their inherited `$this->config` property:

```php
$channel = $this->config->get( 'log.channel' );
```

The default value is used only when a key is absent. A configured `null` value remains `null`. Treat configuration as a stable snapshot for the lifetime of the application container.

Pass resolved configuration into service constructors through container bindings. Application services should not read the environment or the configuration object directly.

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
