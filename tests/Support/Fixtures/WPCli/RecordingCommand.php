<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\WPCli;

use StellarWP\Foundation\WPCli\Command;

final class RecordingCommand extends Command
{
	public bool $registered = false;

	public function runCommand(array $args = [], array $assocArgs = []): int {
		return self::SUCCESS;
	}

	public function register(): void {
		$this->registered = true;
	}

	protected function subcommand(): string {
		return 'recording';
	}

	protected function description(): string {
		return 'Recording command.';
	}

	protected function arguments(): array {
		return [];
	}
}
