# Foundation Container WordPress

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

A WordPress-focused wrapper around [stellarwp/foundation-container](https://github.com/stellarwp/foundation-container).
It exposes the full Foundation DI container API and adds WordPress-specific helpers.

## Installation

```shell
composer require stellarwp/foundation-container-wordpress
```

## Usage

Create a new `ContainerAdapter`, passing in an instance of di52. It implements the
[`Contracts/Container.php`](./Contracts/Container.php) interface, which extends the base
Foundation container contract:

```php
<?php declare(strict_types=1);

namespace My\App;

use lucatume\DI52\Container;
use StellarWP\Foundation\Container\ContainerAdapter;
use StellarWP\Foundation\ContainerWordPress\ContainerAdapter;

// This implements the Contracts/Container.php interface.
$container = new ContainerAdapter(new FoundationContainerAdapter(new Container()));

// Bind the concrete to the interface, so anytime we ask for a container we get this one.
$container->bind(Container::class, $container);
```

Everything the [Foundation Container](https://github.com/stellarwp/foundation-container) can do,
this wrapper can do too — binding, singletons, service providers, contextual bindings, and so on.

## WordPress helpers

On top of the base container API, this wrapper adds hook-aware service provider
registration. These methods are declared on
[`Contracts/Container.php`](./Contracts/Container.php) and implemented in
[`ContainerAdapter.php`](./ContainerAdapter.php).

All of them accept the same optional trailing `...$alias` arguments as the base
`register()` method.

## Hook Prefix

The WordPress container adapter will fire registration hooks when a Provider is being registered. By default, we use
the `'stellarwp/foundation/container/wp/'` as the hook prefix, but you can easily change that by passing a second argument
during the adapter's initialization.

```php
$container = new ContainerAdapter(new FoundationContainerAdapter(new Container()), 'my/hook/prefix/');

add_action(
    'my/hook/prefix/' . My_Provider::class . '/registered',
    function ( string $provider_class, array $aliases ): void {
        // React to My_Provider having been registered.
    },
    10,
    2
);

# Or by using it's alias
add_action(
    'my/hook/prefix/my-alias/registered',
    function ( string $provider_class, array $aliases ): void {
        // React to My_Provider having been registered.
    },
    10,
    2
);

$container->register( My_Provider::class, 'my-alias' );


```

### Registration actions

`register()` is overridden so that, once a provider has been registered, it fires
WordPress actions other code can hook onto:

| Action | Fired |
| --- | --- |
| `stellarwp/foundation/container/wp/{$serviceProviderClass}/registered` | Once, for the registered provider class. |
| `stellarwp/foundation/container/wp/{$alias}/registered` | Once per alias the provider was registered under. |

Both actions pass two arguments to listeners: the registered provider class
(`string`) and the list of aliases (`string[]`).

```php
$container->register( My_Provider::class, 'my-alias' );

add_action(
    'stellarwp/foundation/container/wp/' . My_Provider::class . '/registered',
    function ( string $provider_class, array $aliases ): void {
        // React to My_Provider having been registered.
    },
    10,
    2
);
```

### `register_on_action()`

Register a provider when a WordPress action fires. If the action has already
fired, the provider is registered immediately; otherwise registration is deferred
until the action fires and happens only once.

```php
// Register when `init` fires (or right away if it already has).
$container->register_on_action( 'init', My_Provider::class );
```

### `register_after_provider()`

Register a provider only after another provider has been registered. It builds on
the `.../registered` action above, so the dependant provider is wired up as soon
as the base provider is registered.

```php
// Register Feature_Provider after Core_Provider has been registered.
$container->register_after_provider( Core_Provider::class, Feature_Provider::class );
```

### `register_after_all_actions()`

Register a provider only after *every* one of the given actions has fired. If all
of them have already fired, registration happens immediately; otherwise it waits
for the last one and then registers exactly once.

```php
// Register once both `plugins_loaded` and `init` have fired.
$container->register_after_all_actions( [ 'plugins_loaded', 'init' ], My_Provider::class );
```
