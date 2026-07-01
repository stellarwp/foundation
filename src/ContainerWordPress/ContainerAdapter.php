<?php declare(strict_types=1);

namespace StellarWP\Foundation\ContainerWordPress;

use Closure;
use lucatume\DI52\Container as DI52Container;
use lucatume\DI52\ContainerException;
use StellarWP\Foundation\Container\ContainerAdapter as FoundationContainerAdapter;
use StellarWP\Foundation\ContainerWordPress\Contracts\Container;

/**
 * WordPress-aware container adapter.
 *
 * Wraps the Foundation container so WordPress projects keep the full base
 * container API and gain WordPress-specific helpers. Add WordPress-specific
 * methods here alongside the matching signatures on {@see Container}.
 *
 * @method mixed make(string $id)
 * @method mixed getVar(string $key, mixed|null $default = null)
 * @method void  singletonDecorators($id, array<string> $decorators, ?array<string> $afterBuildMethods = null)
 * @method void  bindDecorators($id, array<string> $decorators, ?array<string> $afterBuildMethods = null)
 * @method void bind(string $id, mixed $implementation = null, ?array $afterBuildMethods = null)
 * @method mixed get(string $id)
 * @method DI52Container get_container()
 * @method bool has(string $id)
 * @method void singleton(string $id, mixed $implementation = null, ?array $afterBuildMethods = null)
 * @method void give(mixed $implementation)
 * @method Closure instance(mixed $id, array $buildArgs = [], ?array $afterBuildMethods = null)
 * @method callable callback(object|string $id, string $method)
 */
final class ContainerAdapter implements Container
{
	private readonly FoundationContainerAdapter $container;

	public function __construct(DI52Container $container) {
		$this->container = new FoundationContainerAdapter($container);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(string $serviceProviderClass, ...$alias): void {
		$this->container->register($serviceProviderClass, ...$alias);

		do_action("{$serviceProviderClass}_registered");

		foreach ($alias as $slug) {
			do_action("{$slug}_registered");
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_after_all_actions(array $actions, string $serviceProviderClass, ...$alias): void {
		$not_done_actions = array_filter(array_map(static fn($action) => did_action( $action ) ? false : $action));
		if (empty($not_done_actions)) {
			// All the actions are done already, we can register immediately.
			$this->register($serviceProviderClass, ...$alias);
			return;
		}

		foreach ($not_done_actions as $not_done_action) {
			$closure = function() use ($not_done_actions, $serviceProviderClass, $alias, &$closure) {
				foreach ($not_done_actions as $nda) {
					remove_action($nda, $closure);
				}

				$this->register_after_all_actions($not_done_actions, $serviceProviderClass, ...$alias);
			};

			add_action($not_done_action, $closure);
		}
	}

	/**
	 * {@inhertiDoc}
	 */
	public function register_on_action(string $action, string $serviceProviderClass, ...$alias): void {
		if (did_action($action)) {
			// If the action has already fired, register the provider immediately.
			$this->register($serviceProviderClass, ...$alias);

			return;
		}

		// If the action has not fired yet, register the provider when/if it does.
		$registration_closure = function() use ($action, $serviceProviderClass, $alias, &$registration_closure) {
			// Remove the closure from the action to avoid calling it again.
			remove_action($action, $registration_closure);
			$this->register($serviceProviderClass, ...$alias);
		};

		add_action($action, $registration_closure);
	}

	/**
	 * {@inhertiDoc}
	 */
	public function register_after_provider(
		string $baseProviderClass,
		string $dependantProviderClass,
		...$alias
	): void {
		$this->register_on_action("{$baseProviderClass}_registered", $dependantProviderClass, ...$alias);
	}

	/**
	 * {@inheritDoc}
	 */
	public function when(string $class): Container {
		$this->container->when($class);

		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function needs(string $id): Container {
		$this->container->needs($id);

		return $this;
	}

	/**
	 * Defer all other calls to the wrapped Foundation container adapter.
	 *
	 * @param string  $name The method name.
	 * @param mixed[] $args Method arguments.
	 */
	public function __call(string $name, array $args): mixed {
		return $this->container->{$name}(...$args);
	}
}
