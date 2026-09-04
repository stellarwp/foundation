<?php declare(strict_types=1);

namespace StellarWP\Foundation\Container\Contracts;

use Closure;
use StellarWP\ContainerContract\ContainerInterface;

/**
 * Registers and resolves Foundation application services.
 *
 * Factory closures receive the narrower {@see Resolver} contract so callers
 * do not depend on the underlying container engine.
 */
interface Container extends ContainerInterface, Resolver
{
	/**
	 * Register a service provider.
	 *
	 * @param class-string $serviceProviderClass
	 * @param string       ...$alias
	 *
	 * @throws \lucatume\DI52\ContainerException
	 */
	public function register(string $serviceProviderClass, ...$alias): void;

	/**
	 * @param class-string|string $class
	 *
	 * @return $this
	 */
	public function when(string $class): Container;

	/**
	 * @param class-string|string $id
	 *
	 * @return $this
	 */
	public function needs(string $id): Container;

	/**
	 * Complete a contextual binding.
	 *
	 * Closure implementations receive a Foundation {@see Resolver}, never the
	 * underlying container implementation.
	 */
	public function give(mixed $implementation): void;

	/**
	 * Add array values to an existing or future binding without replacing previous values.
	 *
	 * @param class-string|string $id
	 *
	 * Closure implementations receive a Foundation {@see Resolver}, never the
	 * underlying container implementation.
	 *
	 * @throws \lucatume\DI52\ContainerException
	 */
	public function mergeArrayVar(string $id, mixed $implementation): void;

	/**
	 * Returns a callable object (Closure) that will build an instance of the specified
	 * class using the specified arguments when called.
	 *
	 * @param array<mixed>  $buildArgs         The arguments passed to the constructor in the order they are provided.
	 * @param string[]|null $afterBuildMethods An array of methods that should be called after the instance is resolved.
	 */
	public function instance(mixed $id, array $buildArgs = [], ?array $afterBuildMethods = null): Closure;

	/**
	 * @param class-string|string|object $id
	 */
	public function callback(string|object $id, string $method): callable;

	/**
	 * Bind a decorator chain that resolves to the same instance on every request.
	 *
	 * The base implementation must be the last decorator.
	 * Closure decorators receive a Foundation {@see Resolver}, never the
	 * underlying container implementation.
	 *
	 * @param class-string|string                          $id
	 * @param non-empty-list<class-string|object|callable> $decorators
	 * @param list<string>|null                            $afterBuildMethods
	 *
	 * @throws \lucatume\DI52\ContainerException
	 */
	public function singletonDecorators(
		string $id,
		array $decorators,
		?array $afterBuildMethods = null,
		bool $afterBuildAll = false
	): void;

	/**
	 * Bind a decorator chain that resolves to a new instance on every request.
	 *
	 * The base implementation must be the last decorator.
	 * Closure decorators receive a Foundation {@see Resolver}, never the
	 * underlying container implementation.
	 *
	 * @param class-string|string                          $id
	 * @param non-empty-list<class-string|object|callable> $decorators
	 * @param list<string>|null                            $afterBuildMethods
	 *
	 * @throws \lucatume\DI52\ContainerException
	 */
	public function bindDecorators(
		string $id,
		array $decorators,
		?array $afterBuildMethods = null,
		bool $afterBuildAll = false
	): void;
}
