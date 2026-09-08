<?php declare(strict_types=1);

namespace StellarWP\Foundation\Container\Contracts;

use StellarWP\Foundation\Container\Exceptions\ContainerException;
use StellarWP\Foundation\Container\Exceptions\NotFoundException;

/**
 * Resolves services inside container factory callbacks without requiring a
 * dependency on the underlying container implementation or registration API.
 */
interface Resolver
{
	/**
	 * Find and return a container entry by its identifier.
	 *
	 * @template T of object
	 *
	 * @param class-string<T>|string $id
	 *
	 * @throws ContainerException When the entry cannot be resolved.
	 * @throws NotFoundException  When the entry does not exist.
	 *
	 * @return ($id is class-string<T> ? T : mixed)
	 */
	public function get(string $id);

	/**
	 * Determine whether the container can resolve an identifier.
	 *
	 * @return bool
	 */
	public function has(string $id);
}
