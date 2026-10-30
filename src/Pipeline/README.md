# Foundation Pipeline

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Foundation Pipeline passes values through ordered, container-resolved pipes. Each pipe can transform the value, stop execution, or continue to the next pipe. It is based on [Laravel's Pipeline implementation](https://github.com/illuminate/pipeline/blob/master/Pipeline.php).

## Installation

```shell
composer require stellarwp/foundation-pipeline
```

## Define a pipe

A pipe receives the current value and a `$next` closure. Pass the transformed value to `$next` to continue:

```php
<?php declare(strict_types=1);

namespace My\App\Titles;

use Closure;

final class TrimTitle
{
    public function handle(string $title, Closure $next): mixed {
        $title = trim($title);

        return $next($title);
    }
}
```

## Run a pipeline

Use the application's [Foundation container](https://github.com/stellarwp/foundation-container#container-configuration) to resolve the pipeline and its class-based pipes. Pipeline does not require a service provider of its own.

```php
use My\App\Titles\TrimTitle;
use StellarWP\Foundation\Pipeline\Pipeline;

$pipeline = $container->get(Pipeline::class);
$result   = $pipeline
    ->through(TrimTitle::class)
    ->send('  New product  ')
    ->thenReturn();

// $result is 'New product'.
```

Use `then()` when a final callback should consume the processed value:

```php
$result = $pipeline
    ->send('  New product  ')
    ->then(static fn (string $title): string => strtoupper($title));

// $result is 'NEW PRODUCT'.
```

Call `send()` before execution. Executing without a value throws `PipelineNotStarted`; `null` is a valid value. A pipe can stop execution by returning without calling `$next`. Exceptions from pipes and the destination propagate unchanged, so a pipe can catch failures from `$next` when it owns recovery.

## Configure a pipeline for a service

Configure the concrete pipeline in a provider. Consumers can request the smaller `Contracts\Pipeline` interface, which exposes `send()`, `then()`, and `thenReturn()`.

For an application service named `TitleImporter` that receives that interface, register this contextual binding in its provider:

```php
use My\App\Titles\TitleImporter;
use My\App\Titles\TrimTitle;
use StellarWP\Foundation\Container\Contracts\Resolver;
use StellarWP\Foundation\Pipeline\Contracts\Pipeline as PipelineContract;
use StellarWP\Foundation\Pipeline\Pipeline;

$this->container->when(TitleImporter::class)
    ->needs(PipelineContract::class)
    ->give(static function (Resolver $resolver): PipelineContract {
        $pipeline = $resolver->get(Pipeline::class);

        return $pipeline->through(TrimTitle::class);
    });
```

> [!WARNING]
> Keep configured pipelines transient with `bind()` rather than `singleton()`. Sending values and configuring pipes changes the instance, so sharing it between consumers can carry state from one operation into another.

## Pipe forms

| Form | Behavior |
| --- | --- |
| Class name | Resolved through the container, then its `handle()` method is called, falling back to `__invoke()`. |
| Non-invokable object | Used directly through its `handle()` method. |
| Callable or invokable object | Called directly with the value and `$next`. |
| `ClassName:first,second` | Resolved through the container; extra string arguments follow the value and `$next`. |

`through()` replaces the pipe list and `pipe()` appends to it. Both accept an array of pipes or separate arguments. Pipes run in the configured order.

Use `via('process')` to choose another method for class-name or non-invokable object pipes. Callables and directly supplied invokable objects are still invoked directly.

For different execution behavior, implement `Contracts\Pipeline`. To transform a downstream result or catch a downstream exception, wrap the `$next()` call inside a pipe.
