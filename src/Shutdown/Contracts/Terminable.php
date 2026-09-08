<?php declare(strict_types=1);

namespace StellarWP\Foundation\Shutdown\Contracts;

/**
 * Work that should run when an application reaches its termination boundary.
 */
interface Terminable
{
	/**
	 * Perform this service's bounded end-of-request work during application shutdown.
	 */
	public function terminate(): void;
}
