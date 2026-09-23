<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Cli;

use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Migration\ValueObjects\MigrationStatus;
use StellarWP\Foundation\Database\Migration\ValueObjects\Step;
use StellarWP\Foundation\WPCli\Command;
use WP_CLI;

use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\get_flag_value;

/**
 * Inspect, preview, apply, and reverse registered database migrations.
 */
final class Migrate extends Command
{
	/**
	 * Receive the application's migration service.
	 */
	public function __construct(
		private readonly Migrator $migrator,
	) {
	}

	/**
	 * Execute one migration operation, or display status when no operation is selected.
	 *
	 * @param list<mixed>          $args
	 * @param array<string, mixed> $assocArgs
	 *
	 * @throws \Throwable When migration planning, execution, or storage fails.
	 */
	public function runCommand(array $args = [], array $assocArgs = []): int {
		$operations = array_values(array_filter(['run', 'rollback', 'refresh'], static fn (string $flag): bool => (bool) get_flag_value($assocArgs, $flag, false)));

		if (count($operations) > 1) {
			WP_CLI::error('Choose only one of --run, --rollback, or --refresh.');
		}

		$operation = $operations[0] ?? null;
		$dryRun    = (bool) get_flag_value($assocArgs, 'dry-run', false);

		if ($dryRun && $operation !== 'run') {
			WP_CLI::error('--dry-run requires --run.');
		}

		if (isset($assocArgs['to']) && ! in_array($operation, ['run', 'rollback'], true)) {
			WP_CLI::error('--to requires --run or --rollback.');
		}

		if (isset($assocArgs['step']) && ($operation !== 'rollback' || isset($assocArgs['to']))) {
			WP_CLI::error('--step requires --rollback and cannot be combined with --to.');
		}

		$steps = filter_var($assocArgs['step'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

		if ($steps === false) {
			WP_CLI::error('--step must be a positive integer.');

			return self::ERROR;
		}

		if ($operation === null) {
			$this->showStatus();

			return self::SUCCESS;
		}

		$target = (string) ($assocArgs['to'] ?? Migrator::LATEST);

		if (! $dryRun && $target === Migrator::NONE) {
			WP_CLI::confirm('Roll back all migrations? This can permanently delete application data.', $assocArgs);
		}

		if ($operation === 'refresh') {
			WP_CLI::confirm('Roll back and rerun all migrations? This can permanently delete application data.', $assocArgs);
			$result = $this->migrator->refresh();
		} elseif ($operation === 'rollback') {
			$result = isset($assocArgs['to']) ? $this->migrator->rollbackTo($target) : $this->migrator->rollback($steps);
		} else {
			$result = $dryRun ? $this->migrator->preview($target) : $this->migrator->migrate($target);
		}

		$this->showSteps($result, $dryRun);

		return self::SUCCESS;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function subcommand(): string {
		return 'migrate';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return 'Inspect, preview, apply, and reverse database migrations.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function arguments(): array {
		return [
			['type' => self::FLAG, 'name' => 'run', 'description' => 'Apply pending migrations, or reconcile to --to.', 'optional' => true],
			['type' => self::FLAG, 'name' => 'rollback', 'description' => 'Reverse the highest applied ID, or select --step or --to.', 'optional' => true],
			['type' => self::FLAG, 'name' => 'refresh', 'description' => 'Reverse and reapply all migrations.', 'optional' => true],
			['type' => self::FLAG, 'name' => 'dry-run', 'description' => 'Preview --run SQL without executing SQL or data callbacks.', 'optional' => true],
			['type' => self::ASSOCIATIVE, 'name' => 'to', 'description' => 'Target migration ID, 0 for none, or latest for all.', 'optional' => true],
			['type' => self::ASSOCIATIVE, 'name' => 'step', 'description' => 'Positive number of applied IDs to reverse.', 'optional' => true],
			['type' => self::FLAG, 'name' => 'yes', 'description' => 'Confirm refresh or --to=0 without prompting.', 'optional' => true],
		];
	}

	private function showStatus(): void {
		format_items('table', array_map(static fn (MigrationStatus $status): array => [
			'id'          => $status->id,
			'migration'   => $status->migration ?? '(missing)',
			'status'      => $status->state(),
			'applied_at'  => $status->appliedAt ?? '',
			'description' => $status->description,
		], $this->migrator->status()), ['id', 'migration', 'status', 'applied_at', 'description']);
	}

	/**
	 * @param list<Step> $steps
	 */
	private function showSteps(array $steps, bool $preview): void {
		foreach ($steps as $step) {
			WP_CLI::line(($step->reverse ? 'Down ' : 'Up ') . $step->id);

			if (! $preview) {
				continue;
			}

			foreach ($step->sql as $sql) {
				WP_CLI::line($sql . ';');
			}

			if ($step->hasDataStep) {
				WP_CLI::line('Data callback will run after schema changes.');
			}
		}

		WP_CLI::success(sprintf('%s %d migration steps.', $preview ? 'Previewed' : 'Completed', count($steps)));
	}
}
