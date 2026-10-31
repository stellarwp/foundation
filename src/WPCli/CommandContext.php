<?php declare(strict_types=1);

namespace StellarWP\Foundation\WPCli;

use StellarWP\Foundation\WPCli\ValueObjects\CommandPrefix;

/**
 * Provides application-level context when a command is registered with WP-CLI.
 */
final readonly class CommandContext
{
	/**
	 * Create a registration context for one application's command namespace.
	 */
	public function __construct(
		private CommandPrefix $prefix
	) {
	}

	/**
	 * Return a subcommand name beneath the configured application prefix.
	 */
	public function name(string $subcommand): string {
		return trim($this->prefix->value . ' ' . $subcommand);
	}
}
