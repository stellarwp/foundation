<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\WPCli;

use StellarWP\Foundation\WPCli\CommandContext;
use StellarWP\Foundation\WPCli\Contracts\RegistrableCommand;

final class RecordingCommand implements RegistrableCommand
{
	public static bool $registered        = false;
	public static ?string $registeredName = null;
	public static int $registrationCount  = 0;

	public function register(CommandContext $context): void {
		self::$registered     = true;
		self::$registeredName = $context->name('recording');
		self::$registrationCount++;
	}
}
