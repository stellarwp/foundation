<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Identifier\Ulid;

use StellarWP\Foundation\Identifier\Ulid\SystemMillisecondClock;
use StellarWP\Foundation\Tests\TestCase;

final class SystemMillisecondClockTest extends TestCase
{
	public function test_it_returns_epoch_milliseconds(): void {
		$before = (int) floor(microtime(true) * 1000);
		$now    = (new SystemMillisecondClock())->milliseconds();
		$after  = (int) floor(microtime(true) * 1000);

		$this->assertGreaterThanOrEqual($before, $now);
		$this->assertLessThanOrEqual($after, $now);
	}
}
