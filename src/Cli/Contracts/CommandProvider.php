<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Contracts;

use Symfony\Component\Console\Command\Command;

/**
 * Supplies console commands without requiring the application to know their source.
 *
 * Implement this when a package or feature wants to contribute one or more CLI
 * commands while keeping its own command construction private.
 */
interface CommandProvider
{
	/**
	 * @return iterable<Command>
	 */
	public function commands(): iterable;
}
