<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Container;

use StellarWP\Foundation\Container\Contracts\Provider;

final class TestProvider extends Provider
{
	public static int $registrationCount = 0;

	public function register(): void {
		self::$registrationCount++;
	}
}
