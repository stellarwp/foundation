# Foundation Shutdown

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Foundation Shutdown runs contributed tasks once, in ascending priority order. Tasks with equal priorities retain contribution order.

```sh
composer require stellarwp/foundation-shutdown:^2.0
```

## Flush an application's logging buffer

Implement `Terminable` for the work performed at shutdown. For a shared Monolog buffer:

```php
use Monolog\Handler\BufferHandler;
use StellarWP\Foundation\Shutdown\Contracts\Terminable;

final readonly class FlushLogs implements Terminable
{
    public function __construct(private BufferHandler $buffer) {
    }

    public function terminate(): void {
        $this->buffer->flush();
    }
}
```

After creating the Foundation container and binding the same `BufferHandler` used by the application logger, register the shutdown provider during WordPress bootstrap and contribute the task:

```php
use StellarWP\Foundation\Container\Contracts\Resolver;
use StellarWP\Foundation\Shutdown\ShutdownProvider;
use StellarWP\Foundation\Shutdown\Task;

$container->register(ShutdownProvider::class);
$container->mergeArrayVar(
    ShutdownProvider::TASKS,
    static function (Resolver $resolver): array {
        $flush = $resolver->get(FlushLogs::class);

        return [
            new Task($flush, 100),
        ];
    }
);
```

Contribute tasks before the runner is first resolved. Register the provider once. It attaches to WordPress `shutdown` at `PHP_INT_MAX`, skipping installation and plugin-uninstallation requests before resolving tasks. It attempts FastCGI or LiteSpeed response finishing when available, then runs the tasks.

An optional registered PSR-3 logger receives diagnostics. A task failure or logging failure does not prevent remaining tasks from running. Termination is attempted once, including when a task fails; it is not a retry queue.

Outside WordPress, construct `Runner` with `Task` objects and call `terminate()` at the application's shutdown boundary. Do not register the WordPress provider in a standalone CLI process. The runner is synchronous and does not enforce timeouts or guarantee delivery after a process crash. Each task must bound its own work; put durable delivery in a queue when required.

For logging, schedule the flush after other tasks that may emit records. A long-running worker needs separate per-job flushes because this runner intentionally terminates only once.
