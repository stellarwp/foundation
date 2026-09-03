<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\WPCli;

use StellarWP\Foundation\WPCli\Command;

final class RecordingCommand extends Command
{
	public static bool $registered        = false;
	public static ?string $registeredName = null;
	public static int $registrationCount  = 0;

	public function runCommand(array $args = [], array $assocArgs = []): int {
		return self::SUCCESS;
	}

	public function register(): void {
		self::$registered     = true;
		self::$registeredName = $this->command();
		self::$registrationCount++;
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
