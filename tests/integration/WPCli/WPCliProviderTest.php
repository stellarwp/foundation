<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\WPCli;

use lucatume\DI52\Container as C;
use stdClass;
use StellarWP\Foundation\Tests\Support\Fixtures\WPCli\RecordingCommand;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;
use StellarWP\Foundation\WPCli\WPCliProvider;
use UnexpectedValueException;

final class WPCliProviderTest extends WPTestCase
{
	public function test_it_registers_configured_commands_on_cli_init(): void {
		$this->container->when(RecordingCommand::class)
			->needs('$commandPrefix')
			->give(static fn (C $c): string => $c->get(WPCliProvider::COMMAND_PREFIX));

		$this->container->singleton(RecordingCommand::class);
		$this->container->mergeArrayVar(WPCliProvider::COMMANDS, static fn (C $c): array => [
			$c->get(RecordingCommand::class),
		]);

		$this->container->register(WPCliProvider::class);

		do_action('cli_init');

		$this->assertTrue($this->container->get(RecordingCommand::class)->registered);
	}

	public function test_it_rejects_invalid_commands_before_registering_any_command(): void {
		$this->container->when(RecordingCommand::class)
			->needs('$commandPrefix')
			->give(static fn (C $c): string => $c->get(WPCliProvider::COMMAND_PREFIX));

		$this->container->singleton(RecordingCommand::class);
		$this->container->mergeArrayVar(WPCliProvider::COMMANDS, static fn (C $c): array => [
			$c->get(RecordingCommand::class),
			new stdClass(),
		]);
		$this->container->register(WPCliProvider::class);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('must extend');

		try {
			do_action('cli_init');
		} finally {
			$this->assertFalse($this->container->get(RecordingCommand::class)->registered);
		}
	}

	public function test_it_rejects_a_non_iterable_command_list(): void {
		$this->container->register(WPCliProvider::class);
		$this->container->bind(WPCliProvider::COMMANDS, 'invalid');

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('commands must be iterable; received string');

		do_action('cli_init');
	}
}
