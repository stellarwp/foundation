<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Lock;

use DateTimeImmutable;
use StellarWP\Foundation\Lock\SystemClock;
use StellarWP\Foundation\Tests\TestCase;

final class SystemClockTest extends TestCase
{
	public function test_it_returns_the_current_time(): void {
		$before = new DateTimeImmutable();
		$now    = (new SystemClock())->now();
		$after  = new DateTimeImmutable();

		$this->assertGreaterThanOrEqual($before, $now);
		$this->assertLessThanOrEqual($after, $now);
	}
}
