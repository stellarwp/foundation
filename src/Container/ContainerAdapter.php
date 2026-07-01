<?php declare(strict_types=1);

namespace StellarWP\Foundation\Container;

use Closure;
use lucatume\DI52\Container as DI52Container;
use lucatume\DI52\ContainerException;
use StellarWP\Foundation\Container\Contracts\Container;

/**
 * @method mixed make(string $id)
 * @method mixed getVar(string $key, mixed|null $default = null)
 * @method void  singletonDecorators($id, array<string> $decorators, ?array<string> $afterBuildMethods = null)
 * @method void  bindDecorators($id, array<string> $decorators, ?array<string> $afterBuildMethods = null)
 */
final class ContainerAdapter implements Container
{
	public function __construct(
		private readonly DI52Container $container
	) {
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
		return $this->container;
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

	/**
	 * {@inheritDoc}
	 */
	public function register(string $serviceProviderClass, ...$alias): void {
		$this->container->register($serviceProviderClass, ...$alias);
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

	public function give(mixed $implementation): void {
		$this->container->give($implementation);
	}

	public function instance(mixed $id, array $buildArgs = [], ?array $afterBuildMethods = null): Closure {
		// @phpstan-ignore-next-line invalid DocBlock comments in DI52
		return $this->container->instance($id, $buildArgs, $afterBuildMethods);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws ContainerException
	 */
	public function mergeArrayVar(string $id, mixed $implementation): void {
		$this->container->mergeArrayVar($id, $implementation);
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
	 * Defer all other calls to the container object.
	 *
	 * @param mixed[] $args
	 */
	public function __call(string $name, array $args): mixed {
		return $this->container->{$name}(...$args);
	}
}
