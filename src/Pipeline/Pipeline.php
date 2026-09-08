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
	/**
	 * The container implementation.
	 */
	private readonly Resolver $container;

	/**
	 * The object being passed through the pipeline.
	 */
	private mixed $passable;

	/**
	 * Whether a passable value, including null, has been supplied.
	 */
	private bool $started = false;

	/**
	 * The array of class pipes.
	 *
	 * @var mixed[]
	 */
	private array $pipes = [];

	/**
	 * The method to call on each pipe.
	 */
	private string $method = 'handle';

	/**
	 * Create a new class instance.
	 */
	public function __construct(Resolver $container) {
		$this->container = $container;
	}

	/**
	 * Set the object being sent through the pipeline.
	 *
	 * @return $this
	 */
	public function send(mixed $passable): static {
		$this->passable = $passable;
		$this->started  = true;

		return $this;
	}

	/**
	 * Set the array of pipes.
	 *
	 * @param array|mixed $pipes
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
	 * @param array|mixed $pipes
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

		$pipeline = array_reduce(
			array_reverse($this->pipes), $this->carry(), $destination
		);

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
	 * @return array<int, mixed>
	 */
	private function parsePipeString(string $pipe): array {
		[$name, $parameters] = array_pad(explode(':', $pipe, 2), 2, []);

		if (is_string($parameters)) {
			$parameters = explode(',', $parameters);
		}

		return [$name, $parameters];
	}
}
