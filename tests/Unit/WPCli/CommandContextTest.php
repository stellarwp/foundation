<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\WPCli;

use StellarWP\Foundation\Tests\TestCase;
use StellarWP\Foundation\WPCli\CommandContext;
use StellarWP\Foundation\WPCli\ValueObjects\CommandPrefix;

final class CommandContextTest extends TestCase
{
	public function test_it_builds_a_command_name_from_the_application_prefix(): void {
		$context = new CommandContext(new CommandPrefix('your-plugin'));

		$this->assertSame('your-plugin sync-products', $context->name('sync-products'));
	}
}
