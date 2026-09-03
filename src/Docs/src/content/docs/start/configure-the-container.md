---
title: Configure the Container
description: Construct the Foundation container and make application configuration available to providers.
sidebar:
  order: 3
---

Foundation adapts [DI52](https://github.com/lucatume/di52) behind its container contract. Construct one adapter during application bootstrap and use that instance for every provider.

## Create the shared container

Construct the adapter in the application's composition root. For example, a root `bootstrap.php` can create and configure the shared instance. Feature classes should receive the services they need rather than creating another container.

```php title="bootstrap.php"
<?php declare(strict_types=1);

use Adbar\Dot;
use lucatume\DI52\Container as DI52Container;
use StellarWP\Foundation\Container\ContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Container;

$container = new ContainerAdapter( new DI52Container() );
$config    = new Dot( require __DIR__ . '/config.php' );

$container->bind( Container::class, $container );
$container->singleton( Dot::class, $config );
```

Binding the Foundation `Container` contract allows application services to request the shared adapter. The `Dot` binding makes the application's configuration available to every Foundation provider.

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

Providers read nested values through their inherited `$this->config` property:

```php
$channel = $this->config->get( 'log.channel' );
```

Pass resolved configuration into service constructors through container bindings. Application services should not read the environment or the `Dot` configuration object directly.

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
