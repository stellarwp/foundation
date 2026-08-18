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

final class CloseRequestLog implements Terminable
{
	public function terminate(): void {
		// Close the request log after the response is sent.
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

`ShutdownProvider` binds the `ShutdownRunner` contract to the ordered task runner,
decorated by `ResponseFinishingRunner`, and attaches it to WordPress's `shutdown`
action at the latest priority. When supported, it finishes the response with
`fastcgi_finish_request()` or `litespeed_finish_request()` before running the
contributed tasks. The PHP worker remains occupied until those tasks finish, so
long-running work still belongs in a proper background queue.

The runner is resolved lazily when the action fires, so features may contribute
tasks after the provider is registered.

With the default provider registered, applications may also invoke the configured
runner chain directly:

```php
use StellarWP\Foundation\Shutdown\Contracts\ShutdownRunner;

$container->get(ShutdownRunner::class)->terminate();
```

Applications that omit the default provider must bind the `ShutdownRunner` contract
in their own provider or construct the concrete
`StellarWP\Foundation\Shutdown\ShutdownRunner` with their desired tasks.

Each runner instance executes only once, including when termination is invoked
recursively. A `Throwable` from one task is isolated so later tasks still run.

## Logging

`ShutdownRunner` accepts an optional PSR-3 logger. When the application binds a
`Psr\Log\LoggerInterface`—including through `foundation-log`—the container injects
it automatically. Applications without a logger require no additional setup.

The runner logs the task count and each task at `debug` level. Task failures are
logged at `error` level with the task class, priority, and actual exception so
compatible loggers retain its message and stack trace. Logger failures are isolated
so diagnostics cannot interrupt termination work.

Output-buffer management, hard task timeouts, and asynchronous execution beyond
the default WordPress shutdown action belong to the consuming application or a
dedicated framework integration.
