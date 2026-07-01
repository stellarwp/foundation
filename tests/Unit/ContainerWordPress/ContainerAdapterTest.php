<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\ContainerWordPress;

use lucatume\DI52\Container as DI52Container;
use lucatume\DI52\ContainerException;
use StellarWP\Foundation\Container\ContainerAdapter as FoundationContainerAdapter;
use StellarWP\Foundation\ContainerWordPress\ContainerAdapter;
use StellarWP\Foundation\ContainerWordPress\Contracts\Container;
use StellarWP\Foundation\Tests\Support\Fixtures\Container\ContainerAdapterSample;
use StellarWP\Foundation\Tests\TestCase;

final class ContainerAdapterTest extends TestCase
{
	private function make_adapter(): ContainerAdapter {
		return new ContainerAdapter(new FoundationContainerAdapter(new DI52Container()));
	}

	public function test_it_implements_the_wordpress_container_contract(): void {
		$this->assertInstanceOf(Container::class, $this->make_adapter());
	}

	public function test_it_binds_and_resolves_through_the_wrapped_container(): void {
		$adapter = $this->make_adapter();
		$adapter->bind(ContainerAdapterSample::class, static fn (): ContainerAdapterSample => new ContainerAdapterSample('value'));

		$this->assertSame('value', $adapter->get(ContainerAdapterSample::class)->value);
	}

	public function test_it_returns_instance_builders_from_the_wrapped_container(): void {
		$adapter = $this->make_adapter();
		$factory = $adapter->instance(ContainerAdapterSample::class, ['value']);

		$this->assertInstanceOf(ContainerAdapterSample::class, $factory());
		$this->assertSame('value', $factory()->value);
	}

	public function test_it_returns_callbacks_from_the_wrapped_container(): void {
		$adapter  = $this->make_adapter();
		$callback = $adapter->callback(new ContainerAdapterSample('value'), 'read');

		$this->assertSame('value', $callback());
	}

	public function test_it_exposes_contextual_bindings_fluently(): void {
		$adapter = $this->make_adapter();

		$this->assertSame($adapter, $adapter->when(ContainerAdapterSample::class));
		$this->assertSame($adapter, $adapter->needs('$value'));
	}

	public function test_it_forwards_unknown_method_calls_to_the_wrapped_container(): void {
		$this->assertSame('fallback', $this->make_adapter()->getVar('missing', 'fallback'));
	}

	public function test_it_exposes_the_underlying_di52_container(): void {
		$di52    = new DI52Container();
		$adapter = new ContainerAdapter(new FoundationContainerAdapter($di52));

		$this->assertSame($di52, $adapter->getContainer());
	}

	public function test_it_merges_array_values_through_the_wrapped_container(): void {
		$adapter = $this->make_adapter();

		$adapter->mergeArrayVar('list', ['a']);
		$adapter->mergeArrayVar('list', ['b', 'c']);

		$this->assertSame(['a', 'b', 'c'], $adapter->getVar('list'));
	}

	public function test_it_merges_associative_array_values_without_replacing_previous_ones(): void {
		$adapter = $this->make_adapter();

		$adapter->mergeArrayVar('config', ['x' => 1]);
		$adapter->mergeArrayVar('config', ['y' => 2]);

		$this->assertSame(['x' => 1, 'y' => 2], $adapter->getVar('config'));
	}

	public function test_it_throws_when_merging_into_an_already_resolved_singleton(): void {
		$adapter = $this->make_adapter();

		$adapter->singleton('resolved', static fn (): array => ['first']);
		$adapter->get('resolved');

		$this->expectException(ContainerException::class);

		$adapter->mergeArrayVar('resolved', ['second']);
	}
}
