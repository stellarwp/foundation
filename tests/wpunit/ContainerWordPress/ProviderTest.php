<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\WPUnit\ContainerWordPress;

use Adbar\Dot;
use lucatume\DI52\Container as DI52Container;
use StellarWP\Foundation\Container\ContainerAdapter as FoundationContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Container as FoundationContainer;
use StellarWP\Foundation\Container\Contracts\Providable;
use StellarWP\Foundation\ContainerWordPress\ContainerAdapter;
use StellarWP\Foundation\ContainerWordPress\Contracts\Container as WPContainerContract;
use StellarWP\Foundation\Tests\Support\Fixtures\ContainerWordPress\FirstProvider;
use StellarWP\Foundation\Tests\Support\Fixtures\ContainerWordPress\WordPressAwareProvider;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;

final class ProviderTest extends WPTestCase
{
	private ContainerAdapter $adapter;

	protected function setUp(): void {
		parent::setUp();

		$this->adapter = new ContainerAdapter(new FoundationContainerAdapter(new DI52Container()));

		$this->adapter->bind(FoundationContainer::class, $this->adapter);
		$this->adapter->bind(WPContainerContract::class, $this->adapter);
		$this->adapter->singleton(Dot::class, new Dot());
	}

	/**
	 * Build the "registered" action name the adapter fires for a provider or alias.
	 */
	private function registered_action(string $identifier): string {
		return 'nexcess/foundation/container/wp/' . $identifier . '/registered';
	}

	/**
	 * Count how many times a WordPress action fires while a callback is attached.
	 */
	private function count_action(string $action): callable {
		$original = did_action($action);

		return static fn (): int => did_action($action) - $original;
	}

	public function test_it_is_a_providable_provider_with_a_wordpress_container(): void {
		$provider = $this->adapter->get(WordPressAwareProvider::class);

		$this->assertInstanceOf(Providable::class, $provider);
		$this->assertInstanceOf(WordPressAwareProvider::class, $provider);
		$this->assertSame($this->adapter, $provider->container());
		$this->assertFalse($provider->isDeferred());
		$this->assertSame([], $provider->provides());
	}

	public function test_it_allows_subclasses_to_use_wordpress_container_methods(): void {
		$fired = $this->count_action($this->registered_action(FirstProvider::class));

		$this->adapter->register(WordPressAwareProvider::class);

		$this->assertSame(0, $fired());

		do_action(WordPressAwareProvider::ACTION);

		$this->assertSame(1, $fired());

		do_action(WordPressAwareProvider::ACTION);

		$this->assertSame(1, $fired());
	}
}
