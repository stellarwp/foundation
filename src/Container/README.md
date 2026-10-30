# Foundation Container

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Foundation Container provides a shared container contract and service provider
base class.

It supports autowiring, contextual bindings, lazy WordPress callbacks,
cross-provider collections, and decorator chains without exposing the underlying
container implementation to application code.

```sh
composer require stellarwp/foundation-container:^2.0
```

## Create the application container

Keep application settings in a `config.php` file that returns an array:

```php
<?php declare(strict_types=1);

return [
    'catalog' => [
        'endpoint' => $_ENV['CATALOG_ENDPOINT'] ?? 'https://example.com/catalog',
    ],
];
```

Load that file during application bootstrap, after the Composer autoloader and any environment setup:

```php
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;

$values        = require __DIR__ . '/config.php';
$configuration = new ArrayConfiguration($values);
$factory       = new ContainerFactory();
$container     = $factory->create($configuration);
```

Use `require`, not `require_once`, when capturing the returned array: a repeated `require_once` returns `true`. `ArrayConfiguration` uses Dot internally for dotted-key lookups such as `catalog.endpoint`.

If several plugins contribute to one application's configuration, load and combine their values before constructing `ArrayConfiguration`. Providers read the completed configuration; they do not merge settings into a mutable Dot instance. Load any `.env` values before requiring the configuration file.

`ContainerFactory` registers the container, resolver and configuration contracts. Configuration is a read-only snapshot for the container lifetime. Missing keys return the supplied default; an explicitly configured `null` remains `null`.

## Register features

Extend `StellarWP\Foundation\Container\Contracts\Provider` for ordinary providers. Its final constructor supplies `$this->container` and `$this->config`; put service dependencies in the services you register.

```php
use StellarWP\Foundation\Container\Contracts\Provider;

final class CatalogProvider extends Provider
{
    public function register(): void {
        $this->container->when(CatalogClient::class)
            ->needs('$endpoint')
            ->give($this->config->get('catalog.endpoint'));

        $this->container->singleton(CatalogClient::class);
    }
}

$container->register(CatalogProvider::class);
```

`CatalogClient` represents the application's own class. Register providers once, in dependency order, before resolving services. Registration is eager; service bindings remain lazy. Repeating registration can repeat hooks and contributions.

Applications with an existing registration abstraction may implement `Contracts\Providable` instead. It requires only `register(): void` and imposes no constructor. The application owns that implementation's injected dependencies. The supplied `Provider` also implements this interface.

Factory closures receive Foundation's `Contracts\Resolver`, not DI52. Use `get()` and `has()` inside factories. Inject resolved services into application code rather than passing the container into business services.

`callback(Service::class, 'method')` creates a lazy callable with stable identity, suitable for WordPress hooks. `mergeArrayVar()` collects contributions from multiple providers. `singletonDecorators()` and `bindDecorators()` compose registered implementations.

Container failures use `Exceptions\ContainerException` and `Exceptions\NotFoundException`. The underlying DI52 container and its undocumented methods are not part of Foundation's public API.
