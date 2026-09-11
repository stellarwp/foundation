<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Shutdown;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use StellarWP\Foundation\Shutdown\ResponseFinishingRunner;
use StellarWP\Foundation\Shutdown\Runner;
use StellarWP\Foundation\Shutdown\Task;
use StellarWP\Foundation\Tests\Support\Fixtures\Shutdown\CallbackTerminable;
use StellarWP\Foundation\Tests\TestCase;

final class ResponseFinishingRunnerTest extends TestCase
{
	protected function setUp(): void {
		parent::setUp();

		if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) {
			$this->markTestSkipped('Native response-finishing functions cannot be replaced by test fixtures.');
		}
	}

	#[RunInSeparateProcess]
	public function test_it_finishes_the_response_and_runs_shutdown_tasks_once(): void {
		require dirname(__DIR__, 2) . '/Support/Fixtures/Shutdown/litespeed-finish-request.php';

		$GLOBALS['foundation_shutdown_calls'] = [];

		$runner = $this->runner();

		$runner->terminate();
		$runner->terminate();

		$this->assertSame(['litespeed', 'task'], $GLOBALS['foundation_shutdown_calls']);

		unset($GLOBALS['foundation_shutdown_calls']);
	}

	#[RunInSeparateProcess]
	public function test_it_prefers_fastcgi_response_finishing(): void {
		require dirname(__DIR__, 2) . '/Support/Fixtures/Shutdown/finish-request-functions.php';

		$GLOBALS['foundation_shutdown_calls'] = [];

		$this->runner()->terminate();

		$this->assertSame(['fastcgi', 'task'], $GLOBALS['foundation_shutdown_calls']);

		unset($GLOBALS['foundation_shutdown_calls']);
	}

	#[RunInSeparateProcess]
	public function test_a_response_finishing_failure_does_not_prevent_shutdown_tasks(): void {
		require dirname(__DIR__, 2) . '/Support/Fixtures/Shutdown/finish-request-functions.php';

		$GLOBALS['foundation_shutdown_calls']           = [];
		$GLOBALS['foundation_shutdown_fastcgi_failure'] = true;

		$this->runner()->terminate();

		$this->assertSame(['fastcgi', 'litespeed', 'task'], $GLOBALS['foundation_shutdown_calls']);

		unset(
			$GLOBALS['foundation_shutdown_calls'],
			$GLOBALS['foundation_shutdown_fastcgi_failure']
		);
	}

	#[RunInSeparateProcess]
	public function test_it_falls_back_when_fastcgi_does_not_finish_the_response(): void {
		require dirname(__DIR__, 2) . '/Support/Fixtures/Shutdown/finish-request-functions.php';

		$GLOBALS['foundation_shutdown_calls']         = [];
		$GLOBALS['foundation_shutdown_fastcgi_false'] = true;

		$this->runner()->terminate();

		$this->assertSame(['fastcgi', 'litespeed', 'task'], $GLOBALS['foundation_shutdown_calls']);

		unset(
			$GLOBALS['foundation_shutdown_calls'],
			$GLOBALS['foundation_shutdown_fastcgi_false']
		);
	}

	private function runner(): ResponseFinishingRunner {
		return new ResponseFinishingRunner(new Runner([
			new Task(new CallbackTerminable(static function (): void {
				$GLOBALS['foundation_shutdown_calls'][] = 'task';
			})),
		]));
	}
}
