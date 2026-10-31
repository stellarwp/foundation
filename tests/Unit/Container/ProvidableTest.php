<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Container;

use lucatume\DI52\Container as DI52Container;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StellarWP\Foundation\Container\ContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Container\Contracts\Providable;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Exceptions\ContainerException;
use StellarWP\Foundation\Tests\Support\Fixtures\Container\CallbackListener;
use StellarWP\Foundation\Tests\Support\Fixtures\Container\IndependentProvider;

final class ProvidableTest extends TestCase
{
	public function test_registration_requires_only_the_eager_registration_capability(): void {
		$contract = new ReflectionClass(Providable::class);
		$methods  = $contract->getMethods();

		$this->assertCount(1, $methods);
		$this->assertSame('register', $methods[0]->getName());
		$base = new ReflectionClass(Provider::class);

		$this->assertTrue($base->implementsInterface(Providable::class));
	}

	public function test_application_provider_resolves_its_dependencies_without_foundation_configuration(): void {
		$adapter  = new ContainerAdapter(new DI52Container());
		$listener = new CallbackListener();
		$adapter->singleton(Container::class, $adapter);
		$adapter->singleton(CallbackListener::class, $listener);

		$adapter->register(IndependentProvider::class, 'application.provider');

		$this->assertSame(1, $listener->calls);
		$this->assertSame($listener, $adapter->get('application.listener'));
		$this->assertSame($adapter->get(IndependentProvider::class), $adapter->get('application.provider'));
	}

	public function test_invalid_resolved_binding_cannot_execute_or_publish_a_provider_alias(): void {
		$adapter  = new ContainerAdapter(new DI52Container());
		$listener = new CallbackListener();
		$adapter->singleton(IndependentProvider::class, $listener);

		try {
			$adapter->register(IndependentProvider::class, 'invalid.provider');
			$this->fail('A non-provider binding must be rejected.');
		} catch (ContainerException $exception) {
			$this->assertStringContainsString('must resolve to', $exception->getMessage());
		}

		$this->assertFalse($adapter->has('invalid.provider'));
		$this->assertSame(0, $listener->calls);
	}
}
