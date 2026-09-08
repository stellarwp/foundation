<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Container;

use RuntimeException;

final class ThrowingContainerService
{
	public function __construct() {
		throw new RuntimeException('Service construction failed.');
	}

	public function run(): void {
	}
}
