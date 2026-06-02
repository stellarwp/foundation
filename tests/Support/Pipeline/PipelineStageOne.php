<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Pipeline;

use Closure;

final class PipelineStageOne
{
	public function handle(string $passable, Closure $next): string {
		$passable = ucwords($passable);

		return $next($passable);
	}
}
