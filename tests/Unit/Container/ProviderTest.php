<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Container;

use StellarWP\Foundation\Container\Contracts\Configuration;
use StellarWP\Foundation\Tests\Support\Fixtures\Container\ConfigurationReadingProvider;
use StellarWP\Foundation\Tests\Support\Fixtures\Container\FixedConfiguration;
use StellarWP\Foundation\Tests\TestCase;

final class ProviderTest extends TestCase
{
	public function test_it_makes_configuration_available_to_providers(): void {
		$this->container->singleton(Configuration::class, new FixedConfiguration([
			'fixture.value' => 'configured',
		]));

		$this->container->register(ConfigurationReadingProvider::class);

		$this->assertSame('configured', $this->container->get(ConfigurationReadingProvider::VALUE));
	}
}
