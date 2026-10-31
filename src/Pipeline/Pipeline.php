<?php declare(strict_types=1);

namespace StellarWP\Foundation\Pipeline;

use Closure;
use StellarWP\Foundation\Container\Contracts\Resolver;
use StellarWP\Foundation\Pipeline\Contracts\Pipeline as PipelineContract;
use StellarWP\Foundation\Pipeline\Exceptions\PipelineNotStarted;
use Throwable;

/**
 * Passes a value through ordered, container-resolved pipes.
 *
 * Configure application behavior through pipes. Alternative executors implement
 * the Pipeline contract.
 */
class Pipeline implements PipelineContract
{
	private mixed $passable;

	/**
	 * Whether a passable value, including null, has been supplied.
	 */
	private bool $started = false;

	/**
	 * Pipes retain their configured order and can be classes, objects, or callables.
	 *
	 * @var mixed[]
	 */
	private array $pipes = [];

	private string $method = 'handle';

	public function __construct(
		private readonly Resolver $container
	) {
	}

	/**
	 * Supply the value for this execution, including an explicit null.
	 *
	 * @return $this
	 */
	public function send(mixed $passable): static {
		$this->passable = $passable;
		$this->started  = true;

		return $this;
	}

	/**
	 * Replace the configured pipes without changing the current value.
	 *
	 * @return $this
	 */
	public function through(mixed $pipes): self {
		$this->pipes = is_array($pipes) ? $pipes : func_get_args();

		return $this;
	}

	/**
	 * Push additional pipes onto the pipeline.
	 *
	 * @return $this
	 */
	public function pipe(mixed $pipes): self {
		array_push($this->pipes, ...(is_array($pipes) ? $pipes : func_get_args()));

		return $this;
	}

	/**
	 * Set the method to call on the pipes.
	 */
	public function via(string $method): self {
		$this->method = $method;

		return $this;
	}

	/**
	 * Run the pipeline with a final destination callback.
	 *
	 * @throws PipelineNotStarted When no value has been supplied through {@see self::send()}.
	 * @throws Throwable          When a pipe, container resolution, or the destination fails.
	 */
	public function then(Closure $destination): mixed {
		if (! $this->started) {
			throw new PipelineNotStarted('Call send() before executing the pipeline.');
		}

		$pipes    = array_reverse($this->pipes);
		$reducer  = $this->carry();
		$pipeline = array_reduce($pipes, $reducer, $destination);

		return $pipeline($this->passable);
	}

	/**
	 * Run the pipeline and return the result.
	 *
	 * @throws PipelineNotStarted When no value has been supplied through {@see self::send()}.
	 * @throws Throwable          When a pipe or container resolution fails.
	 */
	public function thenReturn(): mixed {
		return $this->then(static fn ($passable) => $passable);
	}

	/**
	 * Build the reducer that wraps each pipe around the next stage.
	 */
	private function carry(): Closure {
		return function (Closure $next, mixed $pipe): Closure {
			return function (mixed $passable) use ($next, $pipe): mixed {
				if (is_callable($pipe)) {
					return $pipe($passable, $next);
				}

				if (is_object($pipe)) {
					$parameters = [$passable, $next];
				} else {
					[$name, $arguments] = $this->parsePipeString($pipe);

					$pipe       = $this->container->get($name);
					$parameters = array_merge([$passable, $next], $arguments);
				}

				return method_exists($pipe, $this->method)
					? $pipe->{$this->method}(...$parameters)
					: $pipe(...$parameters);
			};
		};
	}

	/**
	 * Parse full pipe string to get name and parameters.
	 *
	 * @return array{string, list<string>}
	 */
	private function parsePipeString(string $pipe): array {
		$segments   = explode(':', $pipe, 2);
		$name       = $segments[0];
		$parameters = isset($segments[1]) ? explode(',', $segments[1]) : [];

		return [$name, $parameters];
	}
}
