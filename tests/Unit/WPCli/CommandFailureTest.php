<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\WPCli;

use Closure;
use Error;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;
use RuntimeException;
use StellarWP\Foundation\Tests\Support\Fixtures\WPCli\TestCommand;
use StellarWP\Foundation\Tests\Support\Fixtures\WPCli\TestWpCliLogger;
use StellarWP\Foundation\Tests\TestCase;
use StellarWP\Foundation\WPCli\CommandContext;
use StellarWP\Foundation\WPCli\ValueObjects\CommandPrefix;
use WP_CLI;
use WP_CLI\ExitException;

final class CommandFailureTest extends TestCase
{
	private mixed $logger;

	private bool $captureExit;

	protected function setUp(): void {
		parent::setUp();
		$this->logger      = WP_CLI::get_logger();
		$this->captureExit = (bool) (new ReflectionProperty(WP_CLI::class, 'capture_exit'))->getValue();
	}

	protected function tearDown(): void {
		WP_CLI::set_logger($this->logger);
		(new ReflectionProperty(WP_CLI::class, 'capture_exit'))->setValue(null, $this->captureExit);
		parent::tearDown();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_registered_exceptions_are_presented_with_original_debug_details(): void {
		$command          = new TestCommand();
		$command->failure = new RuntimeException('Import failed.', 42, new RuntimeException('Database rejected the row.'));
		$logger           = new TestWpCliLogger();
		$callback         = $this->registerCommand($command, $logger);

		try {
			$callback([], []);
			$this->fail('A command exception must halt execution.');
		} catch (ExitException $exit) {
			$this->assertSame(1, $exit->getCode());
		}

		$this->assertSame([
			'Import failed.',
		], $logger->errorMessages);
		$this->assertSame([
			(string) $command->failure,
		], $logger->debugMessages);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_wp_cli_exit_exceptions_keep_their_identity_and_status(): void {
		$command          = new TestCommand();
		$command->failure = new ExitException('', 7);
		$logger           = new TestWpCliLogger();
		$callback         = $this->registerCommand($command, $logger);

		try {
			$callback([], []);
			$this->fail('WP-CLI exit control must propagate.');
		} catch (ExitException $exit) {
			$this->assertSame($command->failure, $exit);
			$this->assertSame(7, $exit->getCode());
		}

		$this->assertSame([], $logger->errorMessages);
		$this->assertSame([], $logger->debugMessages);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_returned_failure_status_is_preserved(): void {
		$command         = new TestCommand();
		$command->status = 7;
		$logger          = new TestWpCliLogger();
		$callback        = $this->registerCommand($command, $logger);

		try {
			$callback([], []);
			$this->fail('A nonzero status must halt execution.');
		} catch (ExitException $exit) {
			$this->assertSame(7, $exit->getCode());
		}

		$this->assertSame([], $logger->errorMessages);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_php_errors_remain_available_to_the_host_error_handler(): void {
		$command          = new TestCommand();
		$command->failure = new Error('Programming error.');
		$logger           = new TestWpCliLogger();
		$callback         = $this->registerCommand($command, $logger);

		try {
			$callback([], []);
			$this->fail('PHP errors must propagate.');
		} catch (Error $failure) {
			$this->assertSame($command->failure, $failure);
		}

		$this->assertSame([], $logger->errorMessages);
	}

	public function test_direct_command_calls_preserve_the_original_exception(): void {
		$command          = new TestCommand();
		$command->failure = new RuntimeException('Direct failure.');

		try {
			$command->runCommand();
			$this->fail('Direct calls must preserve their exception.');
		} catch (RuntimeException $failure) {
			$this->assertSame($command->failure, $failure);
		}
	}

	/**
	 * Capture the registered callback and WP-CLI output without exiting the test process.
	 */
	private function registerCommand(TestCommand $command, TestWpCliLogger $logger): Closure {
		if (! defined('WP_CLI')) {
			define('WP_CLI', true);
		}

		if (! defined('WP_CLI_ROOT')) {
			define('WP_CLI_ROOT', dirname(__DIR__, 3) . '/vendor/wp-cli/wp-cli');
		}

		require_once WP_CLI_ROOT . '/php/utils.php';
		WP_CLI::set_logger($logger);
		(new ReflectionProperty(WP_CLI::class, 'capture_exit'))->setValue(null, true);
		$command->register(new CommandContext(new CommandPrefix('foundation-failure')));
		$logger->debugMessages = [];

		return WP_CLI::get_deferred_additions()['foundation-failure example']['callable'];
	}
}
