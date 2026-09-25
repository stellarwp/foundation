<?php declare(strict_types=1);

use PHPUnit\Framework\Assert;

/**
 * Verify exception presentation and debug verbosity through the real WP-CLI executable.
 */
final class CommandFailureCest
{
	/**
	 * Ordinary failures show the message without an exception trace.
	 */
	public function test_it_reports_a_readable_error_and_failing_exit_status(WPCLITester $I): void {
		$I->cli([
			'--no-debug',
			'--require=' . dirname(__DIR__) . '/Support/Fixtures/WPCli/register-failing-command.php',
			'foundation-failure',
			'example',
			'value',
		]);
		$I->seeResultCodeIs(1);
		$error = $I->grabLastShellErrorOutput();

		Assert::assertStringContainsString('Error: Import could not complete.', $error);
		Assert::assertStringNotContainsString('Stack trace:', $error);
		Assert::assertStringNotContainsString('Database rejected the row.', $error);
		Assert::assertStringNotContainsString('Fatal error', $error);
	}

	/**
	 * Debug mode includes the original exception chain and stack trace.
	 */
	public function test_debug_mode_includes_the_original_failure_details(WPCLITester $I): void {
		$I->cli([
			'--debug',
			'--require=' . dirname(__DIR__) . '/Support/Fixtures/WPCli/register-failing-command.php',
			'foundation-failure',
			'example',
			'value',
		]);
		$I->seeResultCodeIs(1);
		$error = $I->grabLastShellErrorOutput();

		Assert::assertStringContainsString('Error: Import could not complete.', $error);
		Assert::assertStringContainsString('Database rejected the row.', $error);
		Assert::assertStringContainsString('Stack trace:', $error);
		Assert::assertStringContainsString('RuntimeException', $error);
	}
}
