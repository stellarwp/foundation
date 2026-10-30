# Foundation Container

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Foundation Container provides a shared container contract and service provider
base class, backed by [DI52](https://github.com/lucatume/di52) out of the box.
`ContainerFactory` creates and configures DI52 automatically; applications use
Foundation's contracts to register and resolve their services.

It supports autowiring, contextual bindings, lazy WordPress callbacks,
cross-provider collections, and decorator chains.

## Installation

```shell
composer require stellarwp/foundation-container
```

## Container Configuration

Create a `config.php` in the application root that returns its settings as an
array. The [configuration example below](#making-a-configphp) includes catalog
and logging settings.

In the application's bootstrap file, load the Composer autoloader, load any
optional `.env` file, and create the container before registering providers:

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

This assumes the application autoloads its `My\App` classes through Composer.
`ContainerFactory` registers the shared container under Foundation's `Container`
and `Resolver` contracts and the StellarWP `ContainerInterface`. It also registers
the supplied `Configuration`; applications do not need to bind these manually.

Use `require` when capturing the configuration array. A repeated `require_once`
returns `true` instead of evaluating the file and returning its settings again.

Register providers once, in dependency order, before resolving their services.
Provider registration runs immediately; service bindings remain lazy. Foundation
has no separate provider `boot()` phase. Repeating registration can repeat hooks
and contributions.

## Service Providers

Extend `StellarWP\Foundation\Container\Contracts\Provider` for ordinary providers.
Its final constructor supplies `$this->container` and `$this->config`. Put service
dependencies in the services you register rather than overriding that constructor.

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
`HttpCatalog` receives the configured endpoint without depending on Dot,
Foundation configuration, or the container itself.

Applications with an existing registration base class may implement
`Contracts\Providable` instead. Its only method is `register(): void`, and the
application owns the constructor. The supplied `Provider` implements this same
interface.

## Environment Variable Configuration

`ArrayConfiguration` uses [Dot](https://github.com/adbario/php-dot-notation)
internally for dotted-key lookups. Providers access the Foundation `Configuration`
contract through `$this->config`, rather than receiving Dot directly.

The included [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv) can populate
`$_ENV` from an application's `.env` file. Load environment values before requiring
`config.php`, because that file evaluates them when it runs.

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

The `log` settings are consumed by
[Foundation Log](https://github.com/stellarwp/foundation-log) when that package is
installed and its `LogProvider` is registered. Foundation Container does not
register a logger itself.

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

Inside a provider, use dotted keys to read nested values:

```php
$stream = $this->config->get('log.channels.console.with.stream');
$level  = $this->config->get('log.level', 'info');
```

`get()` uses the supplied default only when the key is absent. A configured `null`
remains `null`. `has()` checks whether a key exists, including keys with null values;
`all()` returns the complete configuration array.

### Configuration lifetime

Configuration exposes read methods only. Assemble shared defaults and overrides
before constructing `ArrayConfiguration` and registering providers that read it.
Providers do not merge settings into a mutable Dot instance.

Changing environment values afterward does not update the configuration. Replacing
the configuration binding also does not rebuild existing providers, service
bindings, or singletons. Tests needing different settings should load their values
and create a fresh configuration and container for each scenario.

Plugin-specific settings can remain in a dedicated settings service instead of
being added to shared application configuration.

## Container Contracts

Factory closures receive Foundation's `Contracts\Resolver`, not DI52. Use `get()`
and `has()` inside factories; inject resolved dependencies into application
services rather than passing them the container.

`callback(Service::class, 'method')` creates a lazy callable with stable identity,
suitable for WordPress hooks. `mergeArrayVar()` collects contributions from
multiple providers. `singletonDecorators()` and `bindDecorators()` compose
registered implementations.

Container failures use `Exceptions\ContainerException` and
`Exceptions\NotFoundException`. DI52 is supplied as the default backend, but its
undocumented methods are not part of Foundation's public API.
