<?php declare(strict_types=1);

namespace StellarWP\Foundation\Shutdown\Contracts;

/**
 * Runs application shutdown work at a lifecycle boundary.
 */
interface ShutdownRunner extends Terminable
{
}
