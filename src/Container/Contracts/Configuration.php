<?php declare(strict_types=1);

namespace StellarWP\Foundation\Container\Contracts;

/**
 * Provides read-only access to application configuration values.
 */
interface Configuration
{
	/**
	 * Return every application configuration value using its original array structure.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array;

	/**
	 * Determine whether a dotted configuration key exists.
	 *
	 * A key with a null value still exists. An exact top-level key takes
	 * precedence over traversing the same dotted path.
	 */
	public function has(string $key): bool;

	/**
	 * Return a configuration value by its dotted key.
	 *
	 * The default applies only when the key is absent. An existing key whose
	 * value is null must return null. An exact top-level key takes precedence
	 * over traversing the same dotted path. The configuration mapping should
	 * remain stable after application providers begin registration.
	 */
	public function get(string $key, mixed $default = null): mixed;
}
