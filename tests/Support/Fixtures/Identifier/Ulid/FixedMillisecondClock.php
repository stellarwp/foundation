<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Identifier\Ulid;

use StellarWP\Foundation\Identifier\Ulid\Contracts\MillisecondClock;

final class FixedMillisecondClock implements MillisecondClock
{
	public function __construct(
		private readonly int $milliseconds
	) {
	}

	public function milliseconds(): int {
		return $this->milliseconds;
	}
}
