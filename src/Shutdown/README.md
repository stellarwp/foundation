# Foundation Shutdown

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Run application termination work once, in a predictable order, without allowing
one failed task to prevent the remaining tasks from running.

## Installation

```shell
composer require stellarwp/foundation-shutdown
```

## Register the provider

Register `ShutdownProvider` through your application's normal Foundation provider
list:

```php
use StellarWP\Foundation\Shutdown\ShutdownProvider;

private array $providers = [
	ShutdownProvider::class,
];
```

The provider has no custom constructor and uses the application's existing
Foundation container and configuration. Package installation alone has no side
effects; consumers may omit this provider and construct the public runner directly
or supply their own provider.

## Create and contribute tasks

Termination work implements the small `Terminable` contract:

```php
use StellarWP\Foundation\Shutdown\Contracts\Terminable;

final class FlushTelemetry implements Terminable
{
	public function terminate(): void {
		// Flush bounded application telemetry.
	}
}
```

Contribute an application's termination work from one provider. Resolve the
concrete tasks lazily so all providers can finish registering before termination
services are constructed. Contributions must be registered before the runner is
resolved:

```php
use lucatume\DI52\Container;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Shutdown\ShutdownProvider as FoundationShutdownProvider;
use StellarWP\Foundation\Shutdown\ShutdownTask;

final class ApplicationShutdownProvider extends Provider
{
	public function register(): void {
		$this->container->singleton(CloseRequestLog::class);
		$this->container->singleton(FlushTelemetry::class);

		$this->container->mergeArrayVar(
			FoundationShutdownProvider::TASKS,
			static fn (Container $container): array => [
				new ShutdownTask($container->get(CloseRequestLog::class), 10),
				new ShutdownTask($container->get(FlushTelemetry::class), 100),
			]
		);
	}
}
```

Register both providers through the application's provider list:

```php
private array $providers = [
	FoundationShutdownProvider::class,
	ApplicationShutdownProvider::class,
];
```

Lower priority values run first. Tasks with the same priority retain their
registration order.

## WordPress shutdown

`ShutdownProvider` automatically attaches the runner to WordPress's `shutdown`
action at the latest priority. The runner is resolved lazily when the action fires,
so features may contribute tasks after the provider is registered.

Applications that need a different lifecycle boundary may omit the default provider
and register their own provider or invoke the runner directly:

```php
use StellarWP\Foundation\Shutdown\ShutdownRunner;

$container->get(ShutdownRunner::class)->terminate();
```

Each runner instance executes only once, including when termination is invoked
recursively. A `Throwable` from one task is isolated so later tasks still run.

## Logging

`ShutdownRunner` accepts an optional PSR-3 logger. When the application binds a
`Psr\Log\LoggerInterface`—including through `foundation-log`—the container injects
it automatically. Applications without a logger require no additional setup.

The runner logs the task count and each task at `debug` level. Task failures are
logged at `error` level with the task class, priority, exception class, and code.
Logger failures are isolated so diagnostics cannot interrupt termination work.

Framework hooks, response finishing, output-buffer management, hard task timeouts,
and asynchronous execution beyond the default WordPress shutdown action belong to
the consuming application or a dedicated framework integration.
