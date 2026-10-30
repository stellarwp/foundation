# Foundation Container

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Foundation Container provides a shared container contract and service provider
base class, backed by [DI52](https://github.com/lucatume/di52) out of the box.

It supports autowiring, contextual bindings, lazy WordPress callbacks,
cross-provider collections, and decorator chains.

## Installation

```shell
composer require stellarwp/foundation-container
```

## Container Configuration

Create the container with `ContainerFactory`, which sets up DI52 and registers
your configuration:

```php
<?php declare(strict_types=1);

namespace My\App;

use Dotenv\Dotenv;
use My\App\Catalog\CatalogProvider;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;

require_once __DIR__ . '/vendor/autoload.php';

// Optionally, load environment variables from an .env file.
$path = __DIR__;

if (is_file($path . '/.env')) {
    $dotenv = Dotenv::createImmutable($path);
    $dotenv->load();
}

// Load the project's configuration. See "Making a config.php" below.
$values        = require $path . '/config.php';
$configuration = new ArrayConfiguration($values);
$factory       = new ContainerFactory();
$container     = $factory->create($configuration);

// Register the application providers.
$providers = [
    CatalogProvider::class,
];

foreach ($providers as $provider) {
    $container->register($provider);
}
```

> [!WARNING]
> Register providers in dependency order. Providers that supply dependencies must be registered before providers that use them.

## Service Providers

Here is an example service provider. The application's `HttpCatalog` implements
`Catalog` and accepts an `$endpoint` constructor argument:

```php
<?php declare(strict_types=1);

namespace My\App\Catalog;

use My\App\Catalog\Contracts\Catalog;
use StellarWP\Foundation\Container\Contracts\Provider;

final class CatalogProvider extends Provider
{
    public function register(): void {
        $endpoint = $this->config->get('catalog.endpoint');

        $this->container->when(HttpCatalog::class)
            ->needs('$endpoint')
            ->give($endpoint);

        $this->container->singleton(Catalog::class, HttpCatalog::class);
    }
}
```

## Environment Variable Configuration

`ArrayConfiguration` uses [Dot](https://github.com/adbario/php-dot-notation) to read
configuration values. Supply environment values directly or load them from an
`.env` file using the included [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv)
before creating the configuration.

Each service provider accesses configuration through `$this->config`. Read settings
in the provider and inject the values into your services, as shown above.

### Making a config.php

A sample `config.php` for a project, with defaults when environment values are not set:

```php
<?php declare(strict_types=1);

return [
    'catalog' => [
        'endpoint' => $_ENV['CATALOG_ENDPOINT'] ?? 'https://example.com/catalog',
    ],
    'log' => [
        'level'    => $_ENV['LOG_LEVEL'] ?? 'info',
        'channel'  => $_ENV['LOG_CHANNEL'] ?? 'errorlog',
        'channels' => [
            'console' => [
                'with' => [
                    'stream' => 'php://stdout',
                ],
            ],
        ],
    ],
];
```

The `log` settings apply when using
[Foundation Log](https://github.com/stellarwp/foundation-log).

Inside a provider, read nested values with dot notation:

```php
// Get the console log stream from config.php.
$stream = $this->config->get('log.channels.console.with.stream');

// Get the log level.
$level = $this->config->get('log.level');
```

An optional `.env` file for these settings could contain:

```dotenv
CATALOG_ENDPOINT=https://example.com/catalog
LOG_LEVEL=info
LOG_CHANNEL=errorlog
```

If using `config.php` in a WordPress plugin, set the environment values in
`wp-config.php` before WordPress loads the plugins:

```php
// Inside wp-config.php.
$_ENV['CATALOG_ENDPOINT'] = 'https://example.com/catalog';
$_ENV['LOG_LEVEL']        = 'info';
$_ENV['LOG_CHANNEL']      = 'errorlog';
```
