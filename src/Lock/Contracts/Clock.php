<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock\Contracts;

use DateTimeImmutable;

/**
 * Provides the current time for lock expiration decisions.
 */
interface Clock
{
	/**
	 * Return the current time used by lock implementations.
	 */
	public function now(): DateTimeImmutable;
}
