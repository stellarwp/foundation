<?php declare(strict_types=1);

namespace StellarWP\Foundation\Container\Contracts;

/**
 * Provides read-only access to application configuration values.
 */
interface Configuration
{
	/**
	 * Return a configuration value by its dotted key.
	 *
	 * The default applies only when the key is absent. An existing key whose
	 * value is null must return null. Configuration should remain stable after
	 * application providers begin registration.
	 */
	public function get(string $key, mixed $default = null): mixed;
}
