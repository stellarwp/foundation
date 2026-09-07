<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Pipeline;

use Closure;

/**
 * Identifies which pipe method the pipeline invokes.
 */
final class MultipleMethodsPipe
{
	/**
	 * Identify invocation through the default pipe method.
	 */
	public function handle(string $value, Closure $next): mixed {
		return $next($value . ':handle');
	}

	/**
	 * Identify invocation through a configured pipe method.
	 */
	public function process(string $value, Closure $next): mixed {
		return $next($value . ':process');
	}

	/**
	 * Identify invocation as a callable object.
	 */
	public function __invoke(string $value, Closure $next): mixed {
		return $next($value . ':invoke');
	}
}
