<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Container;

use StellarWP\ContainerContract\ContainerInterface;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Container\Contracts\Configuration;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Container\Contracts\Resolver;
use StellarWP\Foundation\Tests\TestCase;

final class ContainerFactoryTest extends TestCase
{
	public function test_it_creates_a_container_with_its_foundation_services(): void {
		$configuration = new ArrayConfiguration(['feature' => ['enabled' => true]]);
		$container     = (new ContainerFactory())->create($configuration);

		$this->assertSame($container, $container->get(Container::class));
		$this->assertSame($container, $container->get(Resolver::class));
		$this->assertSame($container, $container->get(ContainerInterface::class));
		$this->assertSame($configuration, $container->get(Configuration::class));
	}
}
