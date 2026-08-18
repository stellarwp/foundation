<?php declare(strict_types=1);

namespace StellarWP\Foundation\Shutdown;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use StellarWP\Foundation\Shutdown\Contracts\Terminable;
use Throwable;

/**
 * Runs contributed termination tasks once in deterministic priority order.
 */
final class ShutdownRunner implements Terminable
{
	/** @var list<ShutdownTask> */
	private array $tasks;

	private bool $terminated = false;

	/**
	 * @param array<array-key, mixed> $tasks
	 */
	public function __construct(
		array $tasks = [],
		private readonly ?LoggerInterface $logger = null
	) {
		foreach ($tasks as $task) {
			if (! $task instanceof ShutdownTask) {
				throw new InvalidArgumentException('Shutdown tasks must be instances of ShutdownTask.');
			}
		}

		$this->tasks = array_values($tasks);
	}

	public function terminate(): void {
		if ($this->terminated) {
			return;
		}

		$this->terminated = true;

		$tasks = $this->orderedTasks();

		$this->log(LogLevel::DEBUG, 'Running shutdown tasks.', [
			'task_count' => count($tasks),
		]);

		foreach ($tasks as $task) {
			$context = [
				'task'     => $task->terminable::class,
				'priority' => $task->priority,
			];

			$this->log(LogLevel::DEBUG, 'Running shutdown task.', $context);

			try {
				$task->terminable->terminate();
			} catch (Throwable $exception) {
				$this->log(LogLevel::ERROR, 'Shutdown task failed.', $context + [
					'exception' => $exception::class,
					'code'      => $exception->getCode(),
				]);
			}
		}
	}

	/**
	 * Logging must not interrupt application termination.
	 *
	 * @param array<string, mixed> $context
	 */
	private function log(string $level, string $message, array $context = []): void {
		try {
			$this->logger?->log($level, $message, $context);
		} catch (Throwable) {
			// The remaining termination work is more important than diagnostics.
		}
	}

	/**
	 * @return list<ShutdownTask>
	 */
	private function orderedTasks(): array {
		/** @var list<array{index: int, task: ShutdownTask}> $indexedTasks */
		$indexedTasks = [];

		foreach ($this->tasks as $index => $task) {
			$indexedTasks[] = [
				'index' => $index,
				'task'  => $task,
			];
		}

		usort(
			$indexedTasks,
			static fn (array $left, array $right): int => ($left['task']->priority <=> $right['task']->priority)
				?: ($left['index'] <=> $right['index'])
		);

		return array_map(
			static fn (array $entry): ShutdownTask => $entry['task'],
			$indexedTasks
		);
	}
}
