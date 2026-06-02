<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Pipeline;

use Closure;

final class PipelineStageTwo
{
	public function handle(string $passable, Closure $next): string {
		$passable = str_ireplace('All', 'All The', $passable);

		return $next($passable);
	}
}
