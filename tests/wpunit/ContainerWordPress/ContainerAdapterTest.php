<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\WPUnit\ContainerWordPress;

use InvalidArgumentException;
use lucatume\DI52\Container as DI52Container;
use StellarWP\Foundation\Container\ContainerAdapter as FoundationContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Container as FoundationContainer;
use StellarWP\Foundation\ContainerWordPress\ContainerAdapter;
use StellarWP\Foundation\ContainerWordPress\Contracts\Container as WPContainerContract;
use StellarWP\Foundation\Tests\Support\Fixtures\ContainerWordPress\FirstProvider;
use StellarWP\Foundation\Tests\Support\Fixtures\ContainerWordPress\SecondProvider;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;

final class ContainerAdapterTest extends WPTestCase
{
	private ContainerAdapter $adapter;

	protected function setUp(): void {
		parent::setUp();

		$this->adapter = new ContainerAdapter(new FoundationContainerAdapter(new DI52Container()));

		$this->adapter->bind(FoundationContainer::class, $this->adapter);
		$this->adapter->bind(WPContainerContract::class, $this->adapter);
	}

	/**
	 * Build the "registered" action name the adapter fires for a provider or alias.
	 */
	private function registered_action(string $identifier): string {
		return 'nexcess/foundation/container/wp/' . $identifier . '/registered';
	}

	private function adapter_with_prefix(string $prefix): ContainerAdapter {
		$adapter = new ContainerAdapter(new FoundationContainerAdapter(new DI52Container()), $prefix);

		$adapter->bind(FoundationContainer::class, $adapter);
		$adapter->bind(WPContainerContract::class, $adapter);

		return $adapter;
	}

	/**
	 * Count how many times a WordPress action fires while a callback is attached.
	 */
	private function count_action(string $action): callable {
		$original = did_action($action);

		return static fn (): int => did_action($action) - $original;
	}

	public function test_register_fires_a_registered_action_for_the_provider(): void {
		$fired = $this->count_action($this->registered_action(FirstProvider::class));

		$this->adapter->register(FirstProvider::class);

		$this->assertSame(1, $fired());

		$this->adapter->register(FirstProvider::class);

		$this->assertSame(2, $fired());
	}

	public function test_register_fires_a_registered_action_for_each_alias(): void {
		$provider = $this->count_action($this->registered_action(FirstProvider::class));
		$alpha    = $this->count_action($this->registered_action('alpha'));
		$beta     = $this->count_action($this->registered_action('beta'));

		$this->adapter->register(FirstProvider::class, 'alpha', 'beta');

		$this->assertSame(1, $provider());
		$this->assertSame(1, $alpha());
		$this->assertSame(1, $beta());

		$this->adapter->register(FirstProvider::class, 'alpha', 'beta');

		$this->assertSame(2, $provider());
		$this->assertSame(2, $alpha());
		$this->assertSame(2, $beta());
	}

	public function test_register_normalizes_custom_prefixes_to_one_trailing_slash(): void {
		foreach (['custom/foundation/container', 'custom/foundation/container/', 'custom/foundation/container///'] as $prefix) {
			$adapter = $this->adapter_with_prefix($prefix);
			$fired   = $this->count_action('custom/foundation/container/' . FirstProvider::class . '/registered');

			$adapter->register(FirstProvider::class);

			$this->assertSame(1, $fired());
		}
	}

	public function test_register_allows_an_empty_custom_prefix(): void {
		$adapter = $this->adapter_with_prefix('');
		$fired   = $this->count_action(FirstProvider::class . '/registered');

		$adapter->register(FirstProvider::class);

		$this->assertSame(1, $fired());
	}

	public function test_register_validates_aliases_before_registering_the_provider(): void {
		$fired = $this->count_action($this->registered_action(FirstProvider::class));

		try {
			$this->adapter->register(FirstProvider::class, '');

			$this->fail('Expected an invalid alias to throw before provider registration.');
		} catch (InvalidArgumentException) {
			$this->assertSame(0, $fired());
		}
	}

	public function test_register_passes_the_provider_class_and_aliases_to_listeners(): void {
		$received = [];

		add_action(
			$this->registered_action(FirstProvider::class),
			static function ($serviceProviderClass = null, $alias = null) use (&$received): void {
				$received = ['provider' => $serviceProviderClass, 'alias' => $alias];
			},
			10,
			2
		);

		$this->adapter->register(FirstProvider::class, 'alpha', 'beta');

		$this->assertSame(FirstProvider::class, $received['provider']);
		$this->assertSame(['alpha', 'beta'], $received['alias']);
	}

	public function test_register_passes_the_provider_class_and_aliases_to_alias_listeners(): void {
		$received = [];

		add_action(
			$this->registered_action('alpha'),
			static function ($serviceProviderClass = null, $alias = null) use (&$received): void {
				$received = ['provider' => $serviceProviderClass, 'alias' => $alias];
			},
			10,
			2
		);

		$this->adapter->register(FirstProvider::class, 'alpha', 'beta');

		$this->assertSame(FirstProvider::class, $received['provider']);
		$this->assertSame(['alpha', 'beta'], $received['alias']);
	}

	public function test_register_on_action_registers_immediately_when_the_action_already_fired(): void {
		$fired = $this->count_action($this->registered_action(FirstProvider::class));

		do_action('foundation_boot_done');

		$this->assertSame(0, $fired());

		$this->adapter->registerOnAction('foundation_boot_done', FirstProvider::class);

		$this->assertSame(1, $fired());
	}

	public function test_register_on_action_defers_registration_until_the_action_fires(): void {
		$fired = $this->count_action($this->registered_action(FirstProvider::class));

		$this->adapter->registerOnAction('foundation_boot_pending', FirstProvider::class);
		$this->assertSame(0, $fired());

		do_action('foundation_boot_pending');
		$this->assertSame(1, $fired());
	}

	public function test_register_on_action_only_registers_once_even_if_the_action_fires_again(): void {
		$fired = $this->count_action($this->registered_action(FirstProvider::class));

		$this->adapter->registerOnAction('foundation_boot_repeat', FirstProvider::class);

		do_action('foundation_boot_repeat');

		$this->assertSame(1, $fired());
		do_action('foundation_boot_repeat');

		$this->assertSame(1, $fired());
	}

	public function test_register_after_provider_registers_the_dependent_once_the_base_registers(): void {
		$base      = $this->count_action($this->registered_action(FirstProvider::class));
		$dependent = $this->count_action($this->registered_action(SecondProvider::class));

		$this->adapter->registerOnProvider(FirstProvider::class, SecondProvider::class);
		$this->assertSame(0, $base());
		$this->assertSame(0, $dependent());

		$this->adapter->register(FirstProvider::class);

		$this->assertSame(1, $base());
		$this->assertSame(1, $dependent());
	}

	public function test_register_after_all_actions_registers_immediately_when_all_actions_are_done(): void {
		$fired = $this->count_action($this->registered_action(FirstProvider::class));

		do_action('foundation_all_first');
		do_action('foundation_all_second');

		$this->adapter->registerAfterAllActions(
			['foundation_all_first', 'foundation_all_second'],
			FirstProvider::class
		);

		$this->assertSame(1, $fired());

		do_action('foundation_all_first');
		do_action('foundation_all_second');

		$this->assertSame(1, $fired());
	}

	public function test_register_after_all_actions_defers_until_the_pending_action_fires(): void {
		$fired = $this->count_action($this->registered_action(FirstProvider::class));

		$this->adapter->registerAfterAllActions(['foundation_all_pending'], FirstProvider::class);
		$this->assertSame(0, $fired());

		do_action('foundation_all_pending');
		$this->assertSame(1, $fired());
	}

	public function test_register_after_all_actions_waits_for_every_action_before_registering(): void {
		$fired = $this->count_action($this->registered_action(FirstProvider::class));

		$this->adapter->registerAfterAllActions(
			['foundation_multi_first', 'foundation_multi_second'],
			FirstProvider::class
		);
		$this->assertSame(0, $fired());

		do_action('foundation_multi_first');
		$this->assertSame(0, $fired());

		do_action('foundation_multi_second');
		$this->assertSame(1, $fired());

		do_action('foundation_multi_first');
		$this->assertSame(1, $fired());

		do_action('foundation_multi_second');
		$this->assertSame(1, $fired());
	}

	public function test_register_after_all_actions_registers_once_when_actions_fire_repeatedly(): void {
		$fired = $this->count_action($this->registered_action(FirstProvider::class));

		$this->adapter->registerAfterAllActions(
			['foundation_repeat_first', 'foundation_repeat_second'],
			FirstProvider::class
		);

		do_action('foundation_repeat_first');
		do_action('foundation_repeat_second');
		$this->assertSame(1, $fired());

		// Firing the same actions again must not register the provider a second time.
		do_action('foundation_repeat_first');
		do_action('foundation_repeat_second');
		$this->assertSame(1, $fired());
	}
}
