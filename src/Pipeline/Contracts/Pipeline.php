<?php declare(strict_types=1);

namespace StellarWP\Foundation\Pipeline\Contracts;

use Closure;
use StellarWP\Foundation\Pipeline\Exceptions\PipelineNotStarted;
use Throwable;

/**
 * Executes a value through a provider-configured pipeline.
 */
interface Pipeline
{
	/**
	 * Set the value that will pass through the configured pipes.
	 *
	 * @return $this
	 */
	public function send(mixed $passable): static;

	/**
	 * Run the pipeline with a final destination callback.
	 *
	 * @throws PipelineNotStarted When no value has been supplied through {@see self::send()}.
	 * @throws Throwable          When a pipe, container resolution, or the destination fails.
	 */
	public function then(Closure $destination): mixed;

	/**
	 * Run the pipeline and return the value produced by its pipes.
	 *
	 * @throws PipelineNotStarted When no value has been supplied through {@see self::send()}.
	 * @throws Throwable          When a pipe or container resolution fails.
	 */
	public function thenReturn(): mixed;
}
