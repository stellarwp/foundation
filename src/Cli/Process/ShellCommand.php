<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Process;

/**
 * Formats argv-style command parts for display or shell execution.
 *
 * Use this helper when commands are stored as `list<string>` values and need a
 * consistently escaped human-readable shell form.
 */
final class ShellCommand
{
	/**
	 * @param list<string> $command
	 */
	public static function format(array $command): string {
		return implode(' ', array_map('escapeshellarg', $command));
	}
}
