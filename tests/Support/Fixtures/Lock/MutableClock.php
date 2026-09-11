<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Lock;

use DateInterval;
use DateTimeImmutable;
use StellarWP\Foundation\Lock\Contracts\Clock;

final class MutableClock implements Clock
{
	public function __construct(
		private DateTimeImmutable $now
	) {
	}

	public function now(): DateTimeImmutable {
		return $this->now;
	}

	public function advance(int $seconds): void {
		$this->now = $this->now->add(new DateInterval(sprintf('PT%dS', $seconds)));
	}
}
