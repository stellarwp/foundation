# Foundation Log

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Foundation Log provides a shared PSR-3 logger using Monolog and the Foundation container.

## Installation

```shell
composer require stellarwp/foundation-log
```

Supports Monolog `^2.11 || ^3.10`.

## Configure the logger

Add the logging settings to the application's `config.php`:

```php
<?php declare(strict_types=1);

return [
    'log' => [
        'channel' => 'errorlog',
        'level'   => $_ENV['LOG_LEVEL'] ?? 'info',
    ],
];
```

Load the configuration when bootstrapping the application container, then register the logging provider:

```php
use Psr\Log\LoggerInterface;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Log\LogProvider;

$values        = require __DIR__ . '/config.php';
$configuration = new ArrayConfiguration($values);
$factory       = new ContainerFactory();
$container     = $factory->create($configuration);
$container->register(LogProvider::class);

$logger = $container->get(LoggerInterface::class);
$logger->info('Order {order_id} paid.', [
    'order_id' => 42,
]);
```

If the application already has a Foundation container, include these settings in its configuration and register `LogProvider` on that existing container.

Inject `Psr\Log\LoggerInterface` into application services. The logger is shared, so handler and processor changes remain in effect for that container's lifetime.

Built-in channels are `errorlog`, `console`, `null`, and `stack` (console plus PHP error log when available). The level defaults to `debug`; select a channel explicitly. PSR-3 placeholders are interpolated while structured context is retained.

## Custom destinations and stacks

Add channel definitions under `log.channels`. Each destination selects a registered Monolog handler; an optional `formatter` selects a registered formatter. For example, with your `BufferedAxiomHandler` already bound in the container:

```php
'log' => [
    'channel' => 'application',
    'level'   => 'info',
    'channels' => [
        'axiom' => [
            'handler' => BufferedAxiomHandler::class,
        ],
        'application' => [
            'channels' => [
                'errorlog',
                'axiom',
            ],
        ],
    ],
],
```

The configured level is the minimum for every destination, including composite handlers. Their own filtering may be stricter.

A custom stream channel can use `Monolog\Handler\StreamHandler` with `with.stream` set to a path or open stream. Stack entries must identify concrete handler channels, not nested stacks. Separate entries must not resolve to the same handler instance. Monolog invokes handlers in reverse insertion order; handler bubbling controls further delivery.

Foundation does not include an Axiom transport or automatically buffer records. The application supplies credentials, transport failure handling, buffer limits and flushing. [Foundation Shutdown](https://github.com/stellarwp/foundation-shutdown) provides an explicit end-of-request flush boundary. Long-running workers should flush between jobs rather than waiting for process termination.
