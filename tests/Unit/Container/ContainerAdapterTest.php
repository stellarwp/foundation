<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Container;

use lucatume\DI52\Container as DI52Container;
use lucatume\DI52\ContainerException;
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

	public function test_it_merges_array_bindings_on_the_wrapped_container(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$adapter->mergeArrayVar('values', ['first']);
		$adapter->mergeArrayVar('values', static fn (): array => ['second']);

		$this->assertSame(['first', 'second'], $adapter->get('values'));
	}

	public function test_it_forwards_unknown_method_calls_to_the_wrapped_container(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$this->assertSame('fallback', $adapter->getVar('missing', 'fallback'));
	}

	public function test_it_merges_array_values_across_multiple_calls(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$adapter->mergeArrayVar('list', ['a']);
		$adapter->mergeArrayVar('list', ['b', 'c']);

		$this->assertSame(['a', 'b', 'c'], $adapter->getVar('list'));
	}

	public function test_it_merges_associative_array_values_without_replacing_previous_ones(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$adapter->mergeArrayVar('config', ['x' => 1]);
		$adapter->mergeArrayVar('config', ['y' => 2]);

		$this->assertSame(['x' => 1, 'y' => 2], $adapter->getVar('config'));
	}

	public function test_it_throws_when_merging_into_an_already_resolved_singleton(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$adapter->singleton('resolved', static fn (): array => ['first']);
		$adapter->get('resolved');

		$this->expectException(ContainerException::class);

		$adapter->mergeArrayVar('resolved', ['second']);
	}
}
