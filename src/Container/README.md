# Foundation Container

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Foundation Container provides a shared container contract and service provider
base class, backed by [DI52](https://github.com/lucatume/di52) out of the box.

## Installation

```shell
composer require stellarwp/foundation-container
```

## Container Configuration

Create a `config.php` in the application root that returns its settings as an
array. The [configuration example below](#making-a-configphp) includes catalog
and logging settings.

Use `ContainerFactory` to create the container with your configuration and register
the application's providers. It creates the DI52 instance automatically:

```php
<?php declare(strict_types=1);

namespace My\App;

use Dotenv\Dotenv;
use My\App\Catalog\CatalogProvider;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;

require_once __DIR__ . '/vendor/autoload.php';

$path = __DIR__;

if (is_file($path . '/.env')) {
    $dotenv = Dotenv::createImmutable($path);
    $dotenv->load();
}

$values        = require $path . '/config.php';
$configuration = new ArrayConfiguration($values);
$factory       = new ContainerFactory();
$container     = $factory->create($configuration);

$providers = [
    CatalogProvider::class,
];

foreach ($providers as $provider) {
    $container->register($provider);
}
```

Register providers in dependency order.

## Service Providers

Extend `Provider` to register services using `$this->container` and read
configuration through `$this->config`.

For example, suppose the application owns a `Catalog` interface and an
`HttpCatalog` implementation whose constructor accepts `string $endpoint`.
The provider binds the implementation and supplies its endpoint from configuration:

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

Application services can now receive `Catalog` through constructor injection.

## Environment Variable Configuration

Use the included [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv) to load
`.env` values before requiring `config.php`, as shown in the bootstrap example.

### Making a config.php

Provide defaults for environment values that have not been set:

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

An optional `.env` file for these settings could contain:

```dotenv
CATALOG_ENDPOINT=https://example.com/catalog
LOG_LEVEL=info
LOG_CHANNEL=errorlog
```

In WordPress, the application can set these values in `wp-config.php` before
WordPress loads the plugins:

```php
$_ENV['CATALOG_ENDPOINT'] = 'https://example.com/catalog';
$_ENV['LOG_LEVEL']        = 'info';
$_ENV['LOG_CHANNEL']      = 'errorlog';
```

### Reading configuration

`ArrayConfiguration` uses [Dot](https://github.com/adbario/php-dot-notation) for
nested lookups. Inside a provider:

```php
$stream = $this->config->get('log.channels.console.with.stream');
$level  = $this->config->get('log.level', 'info');
```

`has()` checks whether a key exists; `all()` returns the complete configuration array.
