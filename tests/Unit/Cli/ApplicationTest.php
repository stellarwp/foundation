<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli;

use StellarWP\Foundation\Cli\Application;
use StellarWP\Foundation\Cli\Contracts\CommandProvider;
use StellarWP\Foundation\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

final class ApplicationTest extends TestCase
{
	public function test_it_registers_commands_from_constructor_arguments(): void {
		$application = new Application([
			new NamedCommand('example:direct'),
		]);

		$this->assertTrue($application->has('example:direct'));
	}

	public function test_it_registers_commands_from_command_providers(): void {
		$application = new Application(commandProviders: [
			new TestCommandProvider(),
		]);

		$this->assertTrue($application->has('example:provider'));
	}
}

final class NamedCommand extends Command
{
	public function __construct(string $name) {
		parent::__construct($name);
	}
}

final class TestCommandProvider implements CommandProvider
{
	/**
	 * @return iterable<Command>
	 */
	public function commands(): iterable {
		yield new NamedCommand('example:provider');
	}
}
