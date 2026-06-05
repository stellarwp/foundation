<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\WPCli;

use StellarWP\Foundation\Tests\TestCase;
use StellarWP\Foundation\WPCli\WPCliStubs;

final class WPCliStubsTest extends TestCase
{
	public function test_it_provides_the_command_stub_path(): void {
		$this->assertFileExists(WPCliStubs::command());
		$this->assertStringEndsWith('/src/WPCli/stubs/command.stub', WPCliStubs::command());
	}
}
