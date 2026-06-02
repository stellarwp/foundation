<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Container;

use StellarWP\Foundation\Tests\Support\Fixtures\Container\TestProvider;
use StellarWP\Foundation\Tests\TestCase;

final class ProviderTest extends TestCase
{
	public function test_it_uses_default_provider_behavior(): void {
		$provider = new TestProvider($this->container);

		$this->assertFalse($provider->isDeferred());
		$this->assertSame([], $provider->provides());

		$provider->boot();
		$this->addToAssertionCount(1);
	}
}
