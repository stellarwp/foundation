<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Container;

use RuntimeException;

final class CallbackListener
{
	public int $calls = 0;

	public function listen(): void {
		$this->calls++;
	}

	public function fail(): void {
		throw new RuntimeException('Callback failed.');
	}
}
