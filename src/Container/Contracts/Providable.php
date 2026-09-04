<?php declare(strict_types=1);

namespace StellarWP\Foundation\Container\Contracts;

interface Providable
{
	/**
	 * Registers bindings in the container.
	 */
	public function register(): void;
}
