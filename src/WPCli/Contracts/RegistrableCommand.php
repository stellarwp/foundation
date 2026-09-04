<?php declare(strict_types=1);

namespace StellarWP\Foundation\WPCli\Contracts;

use StellarWP\Foundation\WPCli\CommandContext;

/**
 * Registers one command with the active WP-CLI application.
 */
interface RegistrableCommand
{
	/**
	 * Register the command using the configured application context.
	 */
	public function register(CommandContext $context): void;
}
