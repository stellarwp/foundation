<?php declare(strict_types=1);

namespace StellarWP\Foundation\Container\Contracts;

/**
 * Allows application-owned registration classes to integrate without inheriting Foundation's provider base.
 */
interface Providable
{
	/**
	 * Register this feature's bindings and hooks before application services are resolved.
	 */
	public function register(): void;
}
