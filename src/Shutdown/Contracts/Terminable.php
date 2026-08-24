<?php declare(strict_types=1);

namespace StellarWP\Foundation\Shutdown\Contracts;

/**
 * Work that should run when an application reaches its termination boundary.
 */
interface Terminable
{
	public function terminate(): void;
}
