<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Process;

use StellarWP\Foundation\Cli\Process\Contracts\ProcessRunner;

/**
 * Runs argv-style commands through the local shell.
 *
 * Use this as the default process runner for CLI features that need to execute
 * generated commands while preserving a mockable `ProcessRunner` boundary.
 */
final class ShellProcessRunner implements ProcessRunner
{
	/**
	 * @param list<string> $command
	 */
	public function run(array $command): int {
		passthru(ShellCommand::format($command), $exitCode);

		return $exitCode;
	}
}
