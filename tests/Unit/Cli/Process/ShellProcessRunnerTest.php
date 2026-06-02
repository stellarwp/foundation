<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Process;

use StellarWP\Foundation\Cli\Process\ShellProcessRunner;
use StellarWP\Foundation\Tests\TestCase;

final class ShellProcessRunnerTest extends TestCase
{
	public function test_it_runs_shell_commands_and_returns_the_exit_code(): void {
		$this->assertSame(0, (new ShellProcessRunner())->run([
			'php',
			'-r',
			'',
		]));
	}
}
