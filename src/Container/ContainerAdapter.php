<?php declare(strict_types=1);

namespace StellarWP\Foundation\Container;

use Closure;
use lucatume\DI52\Container as DI52Container;
use lucatume\DI52\ContainerException as DI52ContainerException;
use lucatume\DI52\NotFoundException as DI52NotFoundException;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Container\Contracts\Providable;
use StellarWP\Foundation\Container\Exceptions\ContainerException;
use StellarWP\Foundation\Container\Exceptions\NotFoundException;
use Throwable;

final class ContainerAdapter implements Container
{
	/** @var array<string, Closure> */
	private array $callbacks = [];

	public function __construct(
		private readonly DI52Container $container
	) {
	}

	/**
	 * @param string[]|null $afterBuildMethods
	 *
	 * @throws ContainerException When the binding cannot be registered.
	 */
	public function bind(string $id, mixed $implementation = null, ?array $afterBuildMethods = null): void {
		$this->call(fn () => $this->container->bind($id, $this->adaptImplementation($implementation), $afterBuildMethods));
	}

	/**
	 * {@inheritDoc}
	 */
	public function get(string $id): mixed {
		return $this->call(fn (): mixed => $this->container->get($id));
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
	 * @throws ContainerException When the singleton cannot be registered.
	 */
	public function singleton(string $id, mixed $implementation = null, ?array $afterBuildMethods = null): void {
		$this->call(fn () => $this->container->singleton($id, $this->adaptImplementation($implementation), $afterBuildMethods));
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(string $serviceProviderClass, ...$alias): void {
		$provider = $this->get($serviceProviderClass);

		if (! $provider instanceof Providable) {
			throw new ContainerException(sprintf(
				'%s must implement %s to be registered as a provider.',
				$serviceProviderClass,
				Providable::class
			));
		}

		$provider->register();
		$this->singleton($serviceProviderClass, $provider);

		foreach ($alias as $providerAlias) {
			$this->singleton($providerAlias, $provider);
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function when(string $class): Container {
		$this->call(fn () => $this->container->when($class));

		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function needs(string $id): Container {
		$this->call(fn () => $this->container->needs($id));

		return $this;
	}

	public function give(mixed $implementation): void {
		$this->call(fn () => $this->container->give($this->adaptImplementation($implementation)));
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws ContainerException When the additive binding cannot be registered.
	 */
	public function mergeArrayVar(string $id, mixed $implementation): void {
		$this->call(fn () => $this->container->mergeArrayVar($id, $this->adaptImplementation($implementation)));
	}

	public function instance(mixed $id, array $buildArgs = [], ?array $afterBuildMethods = null): Closure {
		/** @var Closure $factory */
		$factory = $this->call(
			// @phpstan-ignore-next-line invalid DocBlock comments in DI52
			fn (): Closure => $this->container->instance($id, $buildArgs, $afterBuildMethods)
		);

		return fn (): mixed => $this->resolveDeferred(static fn (): mixed => $factory());
	}

	/**
	 * @param class-string|string|object $id
	 *
	 * @throws ContainerException
	 */
	public function callback(object|string $id, string $method): callable {
		$callbackId = (is_object($id) ? 'object:' . spl_object_id($id) : 'service:' . $id)
			. '::' . $method;

		return $this->callbacks[$callbackId] ??= function (mixed ...$args) use ($id, $method): mixed {
			$instance = is_object($id) ? $id : $this->get($id);

			if (! is_callable([$instance, $method])) {
				throw new ContainerException(sprintf(
					'%s::%s is not a callable container callback.',
					is_object($instance) ? $instance::class : get_debug_type($instance),
					$method
				));
			}

			return $instance->{$method}(...$args);
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function singletonDecorators(
		string $id,
		array $decorators,
		?array $afterBuildMethods = null,
		bool $afterBuildAll = false
	): void {
		$this->call(
			fn () => $this->container->singletonDecorators(
				$id,
				array_map($this->adaptImplementation(...), $decorators),
				$afterBuildMethods,
				$afterBuildAll
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function bindDecorators(
		string $id,
		array $decorators,
		?array $afterBuildMethods = null,
		bool $afterBuildAll = false
	): void {
		$this->call(
			fn () => $this->container->bindDecorators(
				$id,
				array_map($this->adaptImplementation(...), $decorators),
				$afterBuildMethods,
				$afterBuildAll
			)
		);
	}

	/**
	 * Adapt a factory closure so it receives Foundation's resolver rather than DI52.
	 */
	private function adaptImplementation(mixed $implementation): mixed {
		if (! $implementation instanceof Closure) {
			return $implementation;
		}

		$resolver = $this;

		return static fn (): mixed => $implementation($resolver);
	}

	/**
	 * Invoke a DI52 operation while keeping backend exceptions behind Foundation's API.
	 *
	 * @template T
	 *
	 * @param Closure(): T $operation
	 *
	 * @throws ContainerException When the underlying container operation fails.
	 * @throws NotFoundException  When a requested entry does not exist.
	 *
	 * @return T
	 */
	private function call(Closure $operation): mixed {
		try {
			return $operation();
		} catch (DI52NotFoundException $exception) {
			throw new NotFoundException(
				$exception->getMessage(),
				$exception->getCode(),
				$this->previous($exception)
			);
		} catch (DI52ContainerException $exception) {
			throw new ContainerException(
				$exception->getMessage(),
				$exception->getCode(),
				$this->previous($exception)
			);
		}
	}

	/**
	 * Resolve a deferred factory while normalizing failures that bypass DI52's get() boundary.
	 *
	 * @template T
	 *
	 * @param Closure(): T $operation
	 *
	 * @throws ContainerException When the deferred service cannot be resolved.
	 *
	 * @return T
	 */
	private function resolveDeferred(Closure $operation): mixed {
		try {
			return $this->call($operation);
		} catch (ContainerException $exception) {
			throw $exception;
		} catch (Throwable $exception) {
			throw new ContainerException(
				$exception->getMessage(),
				$exception->getCode(),
				$exception
			);
		}
	}

	/**
	 * Preserve the application failure wrapped by DI52 when one is available.
	 */
	private function previous(DI52ContainerException $exception): ?Throwable {
		$previous = $exception->getPrevious();

		while ($previous instanceof DI52ContainerException) {
			$previous = $previous->getPrevious();
		}

		return $previous;
	}
}
