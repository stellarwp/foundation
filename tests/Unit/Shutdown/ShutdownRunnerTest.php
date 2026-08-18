<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Shutdown;

use Error;
use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use StellarWP\Foundation\Shutdown\ShutdownRunner;
use StellarWP\Foundation\Shutdown\ShutdownTask;
use StellarWP\Foundation\Tests\Support\Fixtures\Shutdown\CallbackTerminable;
use StellarWP\Foundation\Tests\TestCase;

final class ShutdownRunnerTest extends TestCase
{
	public function test_it_runs_tasks_by_priority_and_preserves_equal_priority_order(): void {
		$calls = [];

		$runner = new ShutdownRunner([
			$this->recordingTask($calls, 'last', 100),
			$this->recordingTask($calls, 'second', 10),
			$this->recordingTask($calls, 'third', 10),
			$this->recordingTask($calls, 'first', 0),
		]);

		$runner->terminate();

		$this->assertSame(['first', 'second', 'third', 'last'], $calls);
	}

	public function test_it_runs_each_task_only_once(): void {
		$calls  = [];
		$runner = new ShutdownRunner([$this->recordingTask($calls, 'task')]);

		$runner->terminate();
		$runner->terminate();

		$this->assertSame(['task'], $calls);
	}

	public function test_it_is_safe_to_invoke_recursively(): void {
		$calls  = [];
		$runner = new ShutdownRunner();

		$recursive = new CallbackTerminable(static function () use (&$calls, &$runner): void {
			$calls[] = 'recursive';
			$runner->terminate();
		});

		$runner = new ShutdownRunner([
			new ShutdownTask($recursive),
			$this->recordingTask($calls, 'next'),
		]);

		$runner->terminate();

		$this->assertSame(['recursive', 'next'], $calls);
	}

	public function test_a_failed_task_does_not_prevent_later_tasks(): void {
		$calls = [];

		$failing = new CallbackTerminable(static function () use (&$calls): void {
			$calls[] = 'failed';

			throw new Error('Expected test failure.');
		});

		$runner = new ShutdownRunner([
			new ShutdownTask($failing),
			$this->recordingTask($calls, 'completed'),
		]);

		$runner->terminate();

		$this->assertSame(['failed', 'completed'], $calls);
	}

	public function test_it_logs_task_execution_and_failures_when_a_logger_is_available(): void {
		$handler = new TestHandler();
		$logger  = new Logger('shutdown', [$handler]);
		$failure = new Error('Expected test failure.', 42);
		$failing = new CallbackTerminable(static function () use ($failure): void {
			throw $failure;
		});

		$runner = new ShutdownRunner([
			new ShutdownTask($failing, 10),
		], $logger);

		$runner->terminate();

		$records = $handler->getRecords();

		$this->assertCount(3, $records);
		$this->assertSame('Running shutdown tasks.', $records[0]['message']);
		$this->assertSame(['task_count' => 1], $records[0]['context']);
		$this->assertSame('Running shutdown task.', $records[1]['message']);
		$this->assertSame([
			'task'     => CallbackTerminable::class,
			'priority' => 10,
		], $records[1]['context']);
		$this->assertSame('Shutdown task failed.', $records[2]['message']);
		$this->assertSame([
			'task'      => CallbackTerminable::class,
			'priority'  => 10,
			'exception' => $failure,
		], $records[2]['context']);
	}

	public function test_a_failed_logger_does_not_prevent_termination_work(): void {
		$calls  = [];
		$logger = $this->createMock(LoggerInterface::class);

		$logger->method('log')->willThrowException(new Error('Expected logger failure.'));

		$runner = new ShutdownRunner([
			$this->recordingTask($calls, 'completed'),
		], $logger);

		$runner->terminate();

		$this->assertSame(['completed'], $calls);
	}

	public function test_it_accepts_an_empty_task_list(): void {
		$runner = new ShutdownRunner();

		$runner->terminate();
		$runner->terminate();

		$this->addToAssertionCount(1);
	}

	public function test_it_rejects_invalid_task_contributions(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Shutdown tasks must be instances of ShutdownTask.');

		$runner = new ShutdownRunner(['invalid']);
	}

	/**
	 * @param list<string> $calls
	 */
	private function recordingTask(array &$calls, string $name, int $priority = 0): ShutdownTask {
		return new ShutdownTask(
			new CallbackTerminable(static function () use (&$calls, $name): void {
				$calls[] = $name;
			}),
			$priority
		);
	}
}
