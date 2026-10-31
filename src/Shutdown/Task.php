<?php declare(strict_types=1);

namespace StellarWP\Foundation\Shutdown;

use StellarWP\Foundation\Shutdown\Contracts\Terminable;

/**
 * A termination task and its execution priority.
 */
final readonly class Task
{
	public function __construct(
		public Terminable $terminable,
		public int $priority = 0
	) {
	}
}
