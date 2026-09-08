<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\WPCli;

use InvalidArgumentException;
use stdClass;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\Contracts\Configuration;
use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\Tests\Support\Fixtures\WPCli\RecordingCommand;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;
use StellarWP\Foundation\WPCli\CommandContext;
use StellarWP\Foundation\WPCli\ValueObjects\CommandPrefix;
use StellarWP\Foundation\WPCli\WPCliProvider;
use UnexpectedValueException;

final class WPCliProviderTest extends WPTestCase
{
	public function test_it_preserves_the_zero_configuration_command_prefix(): void {
		$this->container->singleton(Configuration::class, new ArrayConfiguration());
		$this->container->register(WPCliProvider::class);

		$commandPrefix = $this->container->get(CommandPrefix::class);
		$context       = $this->container->get(CommandContext::class);

		$this->assertSame('nx', $commandPrefix->value);
		$this->assertSame($commandPrefix, $this->container->get(CommandPrefix::class));
		$this->assertSame($context, $this->container->get(CommandContext::class));
		$this->assertSame('nx example', $context->name('example'));
	}

	public function test_it_uses_the_foundation_prefix_by_default(): void {
		$this->container->singleton(Configuration::class, new ArrayConfiguration([
			'foundation' => [
				'prefix' => 'your-plugin',
			],
		]));

		$this->container->register(WPCliProvider::class);

		$this->assertSame('your-plugin', $this->container->get(CommandPrefix::class)->value);
	}

	public function test_it_uses_the_package_specific_command_prefix(): void {
		$this->container->singleton(Configuration::class, new ArrayConfiguration([
			'foundation' => [
				'prefix' => 'your-plugin',
			],
			'wpcli'      => [
				'command_prefix' => 'your-plugin-tools',
			],
		]));

		$this->container->register(WPCliProvider::class);

		$this->assertSame('your-plugin-tools', $this->container->get(CommandPrefix::class)->value);
	}

	public function test_it_rejects_an_invalid_foundation_prefix_when_the_command_prefix_is_overridden(): void {
		$this->container->singleton(Configuration::class, new ArrayConfiguration([
			'foundation' => [
				'prefix' => 'Invalid Prefix',
			],
			'wpcli'      => [
				'command_prefix' => 'custom',
			],
		]));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('lowercase kebab-case');

		$this->container->register(WPCliProvider::class);
	}

	public function test_it_registers_configured_commands_on_cli_init(): void {
		$this->container->singleton(Configuration::class, new ArrayConfiguration([
			'wpcli' => [
				'command_prefix' => 'your-plugin-tools',
			],
		]));

		RecordingCommand::$registered        = false;
		RecordingCommand::$registeredName    = null;
		RecordingCommand::$registrationCount = 0;
		$this->container->mergeArrayVar(WPCliProvider::COMMANDS, static fn (C $c): array => [
			$c->get(RecordingCommand::class),
		]);

		$this->container->register(WPCliProvider::class);

		do_action('cli_init');

		$this->assertTrue(RecordingCommand::$registered);
		$this->assertSame('your-plugin-tools recording', RecordingCommand::$registeredName);
		$this->assertSame(1, RecordingCommand::$registrationCount);
	}

	public function test_it_registers_commands_only_once_when_the_provider_is_registered_repeatedly(): void {
		RecordingCommand::$registered        = false;
		RecordingCommand::$registeredName    = null;
		RecordingCommand::$registrationCount = 0;
		$this->container->mergeArrayVar(WPCliProvider::COMMANDS, static fn (C $c): array => [
			$c->get(RecordingCommand::class),
		]);

		$this->container->register(WPCliProvider::class);
		$this->container->register(WPCliProvider::class);

		do_action('cli_init');

		$this->assertSame(1, RecordingCommand::$registrationCount);
	}

	public function test_it_rejects_invalid_commands_before_registering_any_command(): void {
		RecordingCommand::$registered        = false;
		RecordingCommand::$registeredName    = null;
		RecordingCommand::$registrationCount = 0;
		$this->container->mergeArrayVar(WPCliProvider::COMMANDS, static fn (C $c): array => [
			$c->get(RecordingCommand::class),
			new stdClass(),
		]);
		$this->container->register(WPCliProvider::class);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('must implement');

		try {
			do_action('cli_init');
		} finally {
			$this->assertFalse(RecordingCommand::$registered);
			$this->assertNull(RecordingCommand::$registeredName);
			$this->assertSame(0, RecordingCommand::$registrationCount);
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
