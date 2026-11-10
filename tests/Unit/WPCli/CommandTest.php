<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\WPCli;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use StellarWP\Foundation\Tests\Support\Fixtures\WPCli\TestCommand;
use StellarWP\Foundation\Tests\TestCase;
use StellarWP\Foundation\WPCli\CommandContext;
use StellarWP\Foundation\WPCli\Exceptions\CommandAlreadyRegistered;
use StellarWP\Foundation\WPCli\ValueObjects\CommandPrefix;
use WP_CLI;
use WP_CLI\Dispatcher\CommandAddition;

final class CommandTest extends TestCase
{
	public function test_it_runs_a_foundation_wp_cli_command(): void {
		$command = new TestCommand();
		$context = new CommandContext(new CommandPrefix('foundation'));

		$this->assertSame(0, $command->runCommand(['value'], ['flag' => true]));
		$this->assertSame(['value'], $command->args);
		$this->assertSame(['flag' => true], $command->assocArgs);
		$this->assertSame('foundation example', $command->name($context));
		$this->assertSame('Example command.', $command->shortDescription());
		$this->assertSame([
			[
				'type'        => 'positional',
				'name'        => 'value',
				'description' => 'Value to process.',
			],
		], $command->synopsis());
	}

	public function test_it_asks_for_normalized_input(): void {
		$command = new TestCommand();

		$result = $command->promptWithInput('Continue?', 'YES' . PHP_EOL);

		$this->assertSame('yes', $result['answer']);
		$this->assertSame('Continue? ', $result['output']);
	}

	public function test_it_exposes_default_input_and_output_streams(): void {
		$command = new TestCommand();

		$this->assertIsResource($command->defaultInput());
		$this->assertIsResource($command->defaultOutput());
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_registers_with_wp_cli_using_the_prefixed_command_name(): void {
		if (! defined('WP_CLI')) {
			define('WP_CLI', true);
		}

		$wpCliRoot = dirname(__DIR__, 3) . '/vendor/wp-cli/wp-cli';

		if (! defined('WP_CLI_ROOT')) {
			define('WP_CLI_ROOT', $wpCliRoot);
		}

		require_once $wpCliRoot . '/php/utils.php';

		$command = new TestCommand();
		$context = new CommandContext(new CommandPrefix('foundation'));

		$command->register($context);
		$deferredAdditions = WP_CLI::get_deferred_additions();

		$this->assertSame('foundation example', $command->registeredName());
		$this->assertArrayHasKey('foundation example', $deferredAdditions);
		$this->assertSame('foundation', $deferredAdditions['foundation example']['parent']);
		$this->assertSame('Example command.', $deferredAdditions['foundation example']['args']['shortdesc']);
		$this->assertSame($command->synopsis(), $deferredAdditions['foundation example']['args']['synopsis']);

		$deferredAdditions['foundation example']['callable'](['value'], ['flag' => true]);

		$this->assertSame(['value'], $command->args);
		$this->assertSame(['flag' => true], $command->assocArgs);

		$this->expectException(CommandAlreadyRegistered::class);
		$this->expectExceptionMessage('has already been registered as "foundation example"');

		$command->register(new CommandContext(new CommandPrefix('other')));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_a_rejected_wp_cli_registration_can_be_retried(): void {
		if (! defined('WP_CLI')) {
			define('WP_CLI', true);
		}

		$wpCliRoot = dirname(__DIR__, 3) . '/vendor/wp-cli/wp-cli';

		if (! defined('WP_CLI_ROOT')) {
			define('WP_CLI_ROOT', $wpCliRoot);
		}

		require_once $wpCliRoot . '/php/utils.php';

		$attempts = 0;
		WP_CLI::add_hook(
			'before_add_command:foundation-retry example',
			static function (CommandAddition $addition) use (&$attempts): void {
				$attempts++;

				if ($attempts === 1) {
					$addition->abort('Rejected by test.');
				}
			}
		);

		$command = new TestCommand();
		$context = new CommandContext(new CommandPrefix('foundation-retry'));

		$command->register($context);

		$this->assertNull($command->registeredName());
		$this->assertArrayNotHasKey('foundation-retry example', WP_CLI::get_deferred_additions());

		$command->register($context);

		$this->assertSame('foundation-retry example', $command->registeredName());
		$this->assertArrayHasKey('foundation-retry example', WP_CLI::get_deferred_additions());
	}
}
