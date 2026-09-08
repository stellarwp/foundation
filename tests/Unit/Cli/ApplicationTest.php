<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli;

use RuntimeException;
use StellarWP\Foundation\Cli\Application;
use StellarWP\Foundation\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

final class ApplicationTest extends TestCase
{
	public function test_it_registers_commands_from_constructor_arguments(): void {
		$application = new Application([new Command('example:direct')]);
		$this->assertTrue($application->has('example:direct'));
	}

	public function test_it_rejects_duplicate_command_names(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('example:duplicate');
		new Application([new Command('example:duplicate'), new Command('example:duplicate')]);
	}

	public function test_it_rejects_alias_collisions(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('example:alias');
		new Application([(new Command('example:first'))->setAliases(['example:alias']), new Command('example:alias')]);
	}

	public function test_it_rejects_collisions_with_symfony_commands(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('list');
		new Application([new Command('list')]);
	}
}
