<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Container;

use lucatume\DI52\Container as DI52Container;
use StellarWP\Foundation\Container\ContainerAdapter;
use StellarWP\Foundation\Tests\Support\Fixtures\Container\ContainerAdapterSample;
use StellarWP\Foundation\Tests\TestCase;

final class ContainerAdapterTest extends TestCase
{
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

	public function test_it_forwards_unknown_method_calls_to_the_wrapped_container(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$this->assertSame('fallback', $adapter->getVar('missing', 'fallback'));
	}
}
