<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Container;

use StellarWP\Foundation\Container\Contracts\Configuration;
use StellarWP\Foundation\Tests\Support\Fixtures\Container\ConfiguredTestProvider;
use StellarWP\Foundation\Tests\Support\Fixtures\Container\FixedConfiguration;
use StellarWP\Foundation\Tests\TestCase;

final class ProviderTest extends TestCase
{
	public function test_it_constructs_configured_providers_from_the_configuration_contract(): void {
		$this->container->singleton(Configuration::class, new FixedConfiguration([
			'fixture.value' => 'configured',
		]));

		$this->container->register(ConfiguredTestProvider::class);

		$this->assertSame('configured', $this->container->get(ConfiguredTestProvider::VALUE));
	}
}
