<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Pipeline;

use Closure;

final class PipelineParameterizedStage
{
	public function handle(string $passable, Closure $next, string $search, string $replace): string {
		return $next(str_replace($search, $replace, $passable));
	}
}
