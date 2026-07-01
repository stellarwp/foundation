<?php declare(strict_types=1);

namespace StellarWP\Foundation\ContainerWordPress\Contracts;

use StellarWP\Foundation\Container\Contracts\Container as FoundationContainer;
use StellarWP\Foundation\Container\Contracts\Providable;

/**
 * WordPress-aware container contract.
 *
 * Extends the Foundation container contract so a WordPress project keeps the full
 * base container API and gains WordPress-specific helpers.
 */
interface Container extends FoundationContainer
{
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
	 * @param string                   $action               The WordPress action the $serviceProviderClass should be registered on.
	 * @param class-string<Providable> $serviceProviderClass The Service Provider to register on $action.
	 * @param string                   $alias                Alias(es) for the $serviceProviderClass.
	 *
	 * @throws \lucatume\DI52\ContainerException
	 */
	public function registerOnAction(string $action, string $serviceProviderClass, ...$alias): void;

	/**
	 * @param class-string<Providable>|string $baseProviderClass      The provider class or id that the $dependantProviderClass
	 *                                                                depends on.
	 * @param class-string<Providable>        $dependantProviderClass The Service Provider to register after
	 *                                                                $baseProviderClass has been registered.
	 * @param string                          ...$alias               Alias(es) for the $dependantProviderClass.
	 *
	 * @throws \lucatume\DI52\ContainerException
	 */
	public function registerOnProvider(string $baseProviderClass, string $dependantProviderClass, ...$alias): void;

	/**
	 * @param list<string>             $actions              A list of actions that all need to be fired for the Provider to be
	 *                                                       registered.
	 * @param class-string<Providable> $serviceProviderClass The Service Provider to register when the last action from $actions
	 *                                                       is fired.
	 * @param string                   ...$alias             Alias(es) for the $serviceProviderClass.
	 *
	 * @throws \lucatume\DI52\ContainerException
	 */
	public function registerAfterAllActions(array $actions, string $serviceProviderClass, ...$alias): void;
}
