<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Container;

use lucatume\DI52\Container as DI52Container;
use RuntimeException;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Configuration;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Container\Contracts\Resolver;
use StellarWP\Foundation\Container\Exceptions\ContainerException;
use StellarWP\Foundation\Container\Exceptions\NotFoundException;
use StellarWP\Foundation\Tests\Support\Fixtures\Container\CallbackListener;
use StellarWP\Foundation\Tests\Support\Fixtures\Container\ContainerAdapterSample;
use StellarWP\Foundation\Tests\Support\Fixtures\Container\TestProvider;
use StellarWP\Foundation\Tests\Support\Fixtures\Container\ThrowingContainerService;
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

	public function test_it_preserves_cached_callback_identity_from_the_wrapped_container(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$adapter->singleton(ContainerAdapterSample::class, new ContainerAdapterSample('value'));

		$this->assertSame(
			$adapter->callback(ContainerAdapterSample::class, 'read'),
			$adapter->callback(ContainerAdapterSample::class, 'read')
		);
	}

	public function test_it_preserves_callback_identity_for_transient_services(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$adapter->bind(ContainerAdapterSample::class, static fn (): ContainerAdapterSample => new ContainerAdapterSample('value'));

		$this->assertSame(
			$adapter->callback(ContainerAdapterSample::class, 'read'),
			$adapter->callback(ContainerAdapterSample::class, 'read')
		);
	}

	public function test_it_preserves_callback_identity_for_an_object(): void {
		$adapter  = new ContainerAdapter(new DI52Container());
		$listener = new CallbackListener();

		$this->assertSame(
			$adapter->callback($listener, 'listen'),
			$adapter->callback($listener, 'listen')
		);
	}

	public function test_it_registers_foundation_providers_without_using_the_backend_provider_lifecycle(): void {
		$adapter                         = new ContainerAdapter(new DI52Container());
		TestProvider::$registrationCount = 0;
		$adapter->bind(Container::class, $adapter);
		$adapter->bind(Configuration::class, new ArrayConfiguration());

		$adapter->register(TestProvider::class, 'test-provider');

		$this->assertSame(1, TestProvider::$registrationCount);
		$this->assertSame($adapter->get(TestProvider::class), $adapter->get('test-provider'));
	}

	public function test_it_rejects_classes_that_do_not_extend_the_provider_base_class(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$this->expectException(ContainerException::class);
		$this->expectExceptionMessage('must extend');

		$adapter->register(CallbackListener::class);
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

	public function test_it_merges_array_values_across_multiple_calls(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$adapter->mergeArrayVar('list', ['a']);
		$adapter->mergeArrayVar('list', ['b', 'c']);

		$this->assertSame(['a', 'b', 'c'], $adapter->get('list'));
	}

	public function test_it_merges_associative_array_values_without_replacing_previous_ones(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$adapter->mergeArrayVar('config', ['x' => 1]);
		$adapter->mergeArrayVar('config', ['y' => 2]);

		$this->assertSame(['x' => 1, 'y' => 2], $adapter->get('config'));
	}

	public function test_it_throws_when_merging_into_an_already_resolved_singleton(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		$adapter->singleton('resolved', static fn (): array => ['first']);
		$adapter->get('resolved');

		$this->expectException(ContainerException::class);

		$adapter->mergeArrayVar('resolved', ['second']);
	}

	public function test_it_translates_missing_entries_to_the_foundation_exception(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		try {
			$adapter->get('missing-entry');
			$this->fail('Expected a missing container entry to throw.');
		} catch (NotFoundException $exception) {
			$this->assertNull($exception->getPrevious());
		}
	}

	public function test_it_preserves_application_failures_without_leaking_backend_exceptions(): void {
		$adapter = new ContainerAdapter(new DI52Container());

		try {
			$adapter->get(ThrowingContainerService::class);
			$this->fail('Expected service construction to throw.');
		} catch (ContainerException $exception) {
			$this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
			$this->assertSame('Service construction failed.', $exception->getPrevious()->getMessage());
		}
	}

	public function test_instance_factories_translate_deferred_container_failures(): void {
		$adapter = new ContainerAdapter(new DI52Container());
		$factory = $adapter->instance(ThrowingContainerService::class);

		try {
			$factory();
			$this->fail('Expected deferred service construction to throw.');
		} catch (ContainerException $exception) {
			$this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
			$this->assertSame('Service construction failed.', $exception->getPrevious()->getMessage());
		}
	}

	public function test_instance_factories_preserve_translated_missing_entry_failures(): void {
		$adapter = new ContainerAdapter(new DI52Container());
		$factory = $adapter->instance('missing-entry');

		try {
			$factory();
			$this->fail('Expected the missing deferred service to throw.');
		} catch (ContainerException $exception) {
			$this->assertNull($exception->getPrevious());
		}
	}

	public function test_callbacks_translate_deferred_container_failures(): void {
		$adapter  = new ContainerAdapter(new DI52Container());
		$callback = $adapter->callback(ThrowingContainerService::class, 'run');

		$this->expectException(ContainerException::class);

		$callback();
	}

	public function test_callback_method_failures_remain_application_exceptions(): void {
		$adapter  = new ContainerAdapter(new DI52Container());
		$callback = $adapter->callback(new CallbackListener(), 'fail');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Callback failed.');

		$callback();
	}

	public function test_it_rejects_non_callable_container_callbacks(): void {
		$adapter  = new ContainerAdapter(new DI52Container());
		$callback = $adapter->callback(new CallbackListener(), 'missing');

		$this->expectException(ContainerException::class);
		$this->expectExceptionMessage('is not a callable container callback');

		$callback();
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
