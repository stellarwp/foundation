<?php declare(strict_types=1);

namespace StellarWP\Foundation\ContainerWordPress;

use Closure;
use lucatume\DI52\Container as DI52Container;
use lucatume\DI52\ContainerException;
use RuntimeException;
use StellarWP\Foundation\Container\ContainerAdapter as FoundationContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Providable;
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
 */
final class ContainerAdapter implements Container
{
	public function __construct(
		private readonly FoundationContainerAdapter $container,
		private readonly string $prefix = 'stellarwp/foundation/container/wp/',
	) {
	}

	/**
	 * Build the "registered" WordPress action name for a service provider or alias.
	 *
	 * @param string $identifier The service provider class or alias slug.
	 *
	 * @return non-empty-string
	 */
	private function registeredAction(string $identifier): string {
		if (!$identifier) {
			throw new RuntimeException( "You need to provide an identifier!" );
		}
		return $this->prefix . $identifier . '/registered';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(string $serviceProviderClass, ...$alias): void {
		$this->container->register($serviceProviderClass, ...$alias);

		/**
		 * Fires after a service provider has been registered in the container.
		 *
		 * The dynamic portion of the hook name, `$serviceProviderClass`, refers to the
		 * fully-qualified class name of the registered service provider.
		 *
		 * @param class-string<Providable> $serviceProviderClass The registered service provider class.
		 * @param string[]                 $alias                The aliases the provider was registered under.
		 */
		do_action($this->registeredAction($serviceProviderClass), $serviceProviderClass, $alias);

		foreach ($alias as $slug) {
			/**
			 * Fires after a service provider has been registered, once per alias.
			 *
			 * The dynamic portion of the hook name, `$slug`, refers to an alias the
			 * service provider was registered under.
			 *
			 * @param class-string<Providable> $serviceProviderClass The registered service provider class.
			 * @param string[]                 $alias                The aliases the provider was registered under.
			 */
			do_action($this->registeredAction($slug), $serviceProviderClass, $alias);
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function registerAfterAllActions(array $actions, string $serviceProviderClass, ...$alias): void {
		$pending = array_values(array_filter($actions, static fn (string $action): bool => ! did_action($action)));

		if ($pending === []) {
			// All the actions have already fired, register the provider immediately.
			$this->register($serviceProviderClass, ...$alias);

			return;
		}

		// A single closure is hooked onto every pending action. Whichever action fires
		// last finds all actions done, registers once, and detaches from the rest.
		$register_when_ready = function () use ($actions, $pending, $serviceProviderClass, $alias, &$register_when_ready): void {
			foreach ($actions as $action) {
				if (! did_action($action)) {
					return;
				}
			}

			// Detach from every pending action so the provider is only registered once.
			foreach ($pending as $action) {
				remove_action($action, $register_when_ready);
			}

			$this->register($serviceProviderClass, ...$alias);
		};

		foreach ($pending as $action) {
			add_action($action, $register_when_ready);
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function registerOnAction(string $action, string $serviceProviderClass, ...$alias): void {
		if (did_action($action)) {
			// If the action has already fired, register the provider immediately.
			$this->register($serviceProviderClass, ...$alias);

			return;
		}

		// If the action has not fired yet, register the provider when/if it does.
		$registration_closure = function () use ($action, $serviceProviderClass, $alias, &$registration_closure) {
			// Remove the closure from the action to avoid calling it again.
			remove_action($action, $registration_closure);
			$this->register($serviceProviderClass, ...$alias);
		};

		add_action($action, $registration_closure);
	}

	/**
	 * {@inheritDoc}
	 */
	public function registerOnProvider(
		string $baseProviderClass,
		string $dependentProviderClass,
		...$alias
	): void {
		$this->registerOnAction($this->registeredAction($baseProviderClass), $dependentProviderClass, ...$alias);
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
	 * @param string[]|null $afterBuildMethods
	 *
	 * @throws \lucatume\DI52\ContainerException
	 */
	public function bind(string $id, mixed $implementation = null, ?array $afterBuildMethods = null): void {
		$this->container->bind($id, $implementation, $afterBuildMethods);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get(string $id): mixed {
		return $this->container->get($id);
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function getContainer(): DI52Container {
		return $this->container->getContainer();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @codeCoverageIgnore
	 */
	public function has(string $id): bool {
		return $this->container->has($id);
	}

	/**
	 * @param string[]|null $afterBuildMethods
	 *
	 * @throws \lucatume\DI52\ContainerException
	 */
	public function singleton(string $id, mixed $implementation = null, ?array $afterBuildMethods = null): void {
		$this->container->singleton($id, $implementation, $afterBuildMethods);
	}

	public function give(mixed $implementation): void {
		$this->container->give($implementation);
	}

	/**
	 * @param array<mixed>  $buildArgs
	 * @param string[]|null $afterBuildMethods
	 */
	public function instance(mixed $id, array $buildArgs = [], ?array $afterBuildMethods = null): Closure {
		return $this->container->instance($id, $buildArgs, $afterBuildMethods);
	}

	/**
	 * @param class-string|string|object $id
	 *
	 * @throws ContainerException
	 */
	public function callback(object|string $id, string $method): callable {
		return $this->container->callback($id, $method);
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
