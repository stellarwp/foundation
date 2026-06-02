<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Process\Contracts;

/**
 * Executes an external process represented as argv parts.
 *
 * Depend on this contract when command features need shell execution but tests
 * should replace the runner without invoking real system commands.
 */
interface ProcessRunner
{
	/**
	 * @param list<string> $command
	 */
	public function run(array $command): int;
}
