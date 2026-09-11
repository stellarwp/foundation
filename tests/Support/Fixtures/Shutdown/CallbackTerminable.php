<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Shutdown;

use Closure;
use StellarWP\Foundation\Shutdown\Contracts\Terminable;

final readonly class CallbackTerminable implements Terminable
{
	public function __construct(
		private Closure $callback
	) {
	}

	public function terminate(): void {
		($this->callback)();
	}
}
