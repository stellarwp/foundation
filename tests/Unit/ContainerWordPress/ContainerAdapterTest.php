<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\ContainerWordPress;

use lucatume\DI52\Container as DI52Container;
use StellarWP\Foundation\ContainerWordPress\ContainerAdapter;
use StellarWP\Foundation\ContainerWordPress\Contracts\Container;
use StellarWP\Foundation\Tests\Support\Fixtures\Container\ContainerAdapterSample;
use StellarWP\Foundation\Tests\TestCase;

final class ContainerAdapterTest extends TestCase
{
	public function test_it_implements_the_wordpress_container_contract(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$this->assertInstanceOf(Container::class, $adapter);
	}

	public function test_it_binds_and_resolves_through_the_wrapped_container(): void {
		$adapter = new ContainerAdapter(new DI52Container());
		$adapter->bind(ContainerAdapterSample::class, static fn (): ContainerAdapterSample => new ContainerAdapterSample('value'));

		$this->assertSame('value', $adapter->get(ContainerAdapterSample::class)->value);
	}

	public function test_it_returns_instance_builders_from_the_wrapped_container(): void {
		$adapter = new ContainerAdapter(new DI52Container());
		$factory = $adapter->instance(ContainerAdapterSample::class, ['value']);

		$this->assertInstanceOf(ContainerAdapterSample::class, $factory());
		$this->assertSame('value', $factory()->value);
	}

	public function test_it_returns_callbacks_from_the_wrapped_container(): void {
		$adapter  = new ContainerAdapter(new DI52Container());
		$callback = $adapter->callback(new ContainerAdapterSample('value'), 'read');

		$this->assertSame('value', $callback());
	}

	public function test_it_exposes_contextual_bindings_fluently(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$this->assertSame($adapter, $adapter->when(ContainerAdapterSample::class));
		$this->assertSame($adapter, $adapter->needs('$value'));
	}

	public function test_it_forwards_unknown_method_calls_to_the_wrapped_container(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$this->assertSame('fallback', $adapter->getVar('missing', 'fallback'));
	}

	public function test_it_exposes_the_underlying_di52_container(): void {
		$di52    = new DI52Container();
		$adapter = new ContainerAdapter($di52);

		$this->assertSame($di52, $adapter->getContainer());
	}
}
