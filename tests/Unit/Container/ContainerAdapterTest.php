<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Container;

use lucatume\DI52\Container as DI52Container;
use lucatume\DI52\ContainerException;
use StellarWP\Foundation\Container\ContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Resolver;
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

		$adapter->bind('second', static fn (): string => 'second');
		$adapter->mergeArrayVar('values', ['first']);
		$adapter->mergeArrayVar('values', static fn (Resolver $resolver): array => [$resolver->get('second')]);

		$this->assertSame(['first', 'second'], $adapter->get('values'));
	}

	public function test_it_supplies_the_foundation_resolver_to_binding_factories(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$adapter->bind('dependency', static fn (): string => 'resolved');
		$adapter->bind('service', static fn (Resolver $resolver): mixed => $resolver->has('dependency')
			? $resolver->get('dependency')
			: null);

		$this->assertSame('resolved', $adapter->get('service'));
	}

	public function test_it_supplies_the_foundation_resolver_to_singleton_factories(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$adapter->singleton('service', static fn (Resolver $resolver): Resolver => $resolver);

		$this->assertSame($adapter, $adapter->get('service'));
		$this->assertSame($adapter->get('service'), $adapter->get('service'));
	}

	public function test_it_supplies_the_foundation_resolver_to_contextual_factories(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$adapter->bind('dependency', static fn (): string => 'resolved');
		$adapter->when(ContainerAdapterSample::class)
			->needs('$value')
			->give(static fn (Resolver $resolver): string => $resolver->get('dependency'));

		$this->assertSame('resolved', $adapter->get(ContainerAdapterSample::class)->value);
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

	public function test_it_supplies_the_foundation_resolver_to_decorator_closures(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$adapter->bindDecorators('service', [
			static fn (Resolver $resolver): ContainerAdapterSample => new ContainerAdapterSample(
				$resolver === $adapter ? 'resolved' : 'unexpected'
			),
		]);

		$service = $adapter->get('service');

		$this->assertInstanceOf(ContainerAdapterSample::class, $service);
		$this->assertSame('resolved', $service->value);
	}

	public function test_it_forwards_singleton_decorator_bindings(): void {
		$container = $this->createMock(DI52Container::class);
		$adapter   = new ContainerAdapter($container);
		$decorator = static fn (Resolver $resolver): Resolver => $resolver;

		$container->expects($this->once())
			->method('singletonDecorators')
			->with(
				'service',
				$this->callback(function (array $decorators) use ($adapter): bool {
					$this->assertCount(2, $decorators);
					$this->assertSame($adapter, $decorators[0]());
					$this->assertSame(ContainerAdapterSample::class, $decorators[1]);

					return true;
				}),
				['read'],
				true
			);

		$adapter->singletonDecorators('service', [$decorator, ContainerAdapterSample::class], ['read'], true);
	}

	public function test_it_forwards_decorator_bindings(): void {
		$container = $this->createMock(DI52Container::class);
		$adapter   = new ContainerAdapter($container);
		$decorator = static fn (Resolver $resolver): Resolver => $resolver;

		$container->expects($this->once())
			->method('bindDecorators')
			->with(
				'service',
				$this->callback(function (array $decorators) use ($adapter): bool {
					$this->assertCount(2, $decorators);
					$this->assertSame($adapter, $decorators[0]());
					$this->assertSame(ContainerAdapterSample::class, $decorators[1]);

					return true;
				}),
				['read'],
				true
			);

		$adapter->bindDecorators('service', [$decorator, ContainerAdapterSample::class], ['read'], true);
	}
}
