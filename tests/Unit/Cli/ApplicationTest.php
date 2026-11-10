<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
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

	/**
	 * @dataProvider disabledCommandOrders
	 */
	#[DataProvider('disabledCommandOrders')]
	public function test_disabled_commands_do_not_conflict_with_enabled_commands(bool $disabledFirst, bool $alias): void {
		$enabled  = new Command('example:shared');
		$disabled = $this->getMockBuilder(Command::class)
			->setConstructorArgs([$alias ? 'example:disabled' : 'example:shared'])
			->onlyMethods(['isEnabled'])
			->getMock();
		$disabled->setAliases($alias ? ['example:shared'] : []);
		$disabled->expects($this->once())->method('isEnabled')->willReturnCallback(function () use ($disabled): bool {
			$this->assertInstanceOf(Application::class, $disabled->getApplication());

			return false;
		});

		$application = new Application($disabledFirst ? [$disabled, $enabled] : [$enabled, $disabled]);

		$this->assertSame($enabled, $application->get('example:shared'));
		$this->assertNull($disabled->getApplication());
		$this->assertFalse($application->has('example:disabled'));
	}

	/**
	 * Supply both contribution orders for a shared command name or alias.
	 *
	 * @return array<string, array{bool, bool}>
	 */
	public static function disabledCommandOrders(): array {
		return [
			'enabled first, shared name'   => [false, false],
			'disabled first, shared name'  => [true, false],
			'enabled first, shared alias'  => [false, true],
			'disabled first, shared alias' => [true, true],
		];
	}

	public function test_it_checks_enabled_commands_once_with_the_application_available(): void {
		$command = $this->getMockBuilder(Command::class)
			->setConstructorArgs(['example:conditional'])
			->onlyMethods(['isEnabled'])
			->getMock();
		$command->expects($this->once())->method('isEnabled')->willReturnCallback(function () use ($command): bool {
			$this->assertInstanceOf(Application::class, $command->getApplication());

			return true;
		});

		$application = new Application([$command]);

		$this->assertSame($command, $application->get('example:conditional'));
	}

	public function test_disabled_commands_do_not_conflict_with_builtin_names_or_aliases(): void {
		foreach ([false, true] as $alias) {
			$command = $this->getMockBuilder(Command::class)
				->setConstructorArgs([$alias ? 'example:disabled' : 'list'])
				->onlyMethods(['isEnabled'])
				->getMock();
			$command->setAliases($alias ? ['list'] : []);
			$command->expects($this->once())->method('isEnabled')->willReturn(false);

			$application = new Application([$command]);

			$this->assertNotSame($command, $application->get('list'));
			$this->assertNull($command->getApplication());
		}
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
