<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Cli;

use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\WPCli\Command;
use WP_CLI;

use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\get_flag_value;

/**
 * WP-CLI command for inspecting and applying Foundation database migrations.
 */
final class Migrate extends Command
{
	private const string FLAG_RUN        = 'run';
	private const string FLAG_ROLLBACK   = 'rollback';
	private const string FLAG_REFRESH    = 'refresh';
	private const string FLAG_DROP_STORE = 'drop-store';
	private const string FLAG_INITIALIZE = 'initialize';
	private const string FLAG_YES        = 'yes';

	public function __construct(
		private readonly Migrator $migrator
	) {
	}

	/**
	 * Dispatch the selected migration operation or display migration status.
	 *
	 * @param list<mixed>         $args
	 * @param array<string,mixed> $assocArgs
	 */
	public function runCommand(array $args = [], array $assocArgs = []): int {
		$operation = $this->selectedOperation($assocArgs);

		if ($operation !== null && $operation !== self::FLAG_INITIALIZE && ! $this->migrator->isInitialized()) {
			WP_CLI::error($this->uninitializedMessage());
		}

		match ($operation) {
			self::FLAG_RUN        => $this->runMigrations(),
			self::FLAG_ROLLBACK   => $this->rollbackMigrations(),
			self::FLAG_REFRESH    => $this->refreshMigrations($assocArgs),
			self::FLAG_DROP_STORE => $this->dropStore($assocArgs),
			self::FLAG_INITIALIZE => $this->initializeStore(),
			default               => $this->showStatus(),
		};

		return self::SUCCESS;
	}

	protected function subcommand(): string {
		return 'migrate';
	}

	protected function description(): string {
		return 'List and manage database migrations.';
	}

	protected function arguments(): array {
		return [
			[
				'type'        => self::FLAG,
				'name'        => self::FLAG_RUN,
				'description' => 'Run pending migrations.',
				'optional'    => true,
				'default'     => false,
			],
			[
				'type'        => self::FLAG,
				'name'        => self::FLAG_ROLLBACK,
				'description' => 'Rollback the latest migration batch.',
				'optional'    => true,
				'default'     => false,
			],
			[
				'type'        => self::FLAG,
				'name'        => self::FLAG_REFRESH,
				'description' => 'Rollback and rerun all migrations.',
				'optional'    => true,
				'default'     => false,
			],
			[
				'type'        => self::FLAG,
				'name'        => self::FLAG_DROP_STORE,
				'description' => 'Drop only the migration ledger.',
				'optional'    => true,
				'default'     => false,
			],
			[
				'type'        => self::FLAG,
				'name'        => self::FLAG_INITIALIZE,
				'description' => 'Initialize or reconcile Foundation migration storage.',
				'optional'    => true,
				'default'     => false,
			],
			[
				'type'        => self::FLAG,
				'name'        => self::FLAG_YES,
				'description' => 'Skip confirmation prompts for destructive actions.',
				'optional'    => true,
				'default'     => false,
			],
		];
	}

	private function showStatus(): void {
		if (! $this->migrator->isInitialized()) {
			WP_CLI::warning($this->uninitializedMessage());
		}

		format_items('table', array_map(
			static fn ($status): array => [
				'migration' => $status->migration,
				'status'    => $status->state(),
				'batch'     => $status->batch ?? '',
				'ran_at'    => $status->ranAt?->format('Y-m-d H:i:s') ?? '',
			],
			$this->migrator->status()
		), [
			'migration',
			'status',
			'batch',
			'ran_at',
		]);
	}

	/**
	 * Select the one migration operation requested by the command flags.
	 *
	 * @param array<string, mixed> $assocArgs
	 */
	private function selectedOperation(array $assocArgs): ?string {
		$operations = [
			self::FLAG_RUN,
			self::FLAG_ROLLBACK,
			self::FLAG_REFRESH,
			self::FLAG_DROP_STORE,
			self::FLAG_INITIALIZE,
		];
		$selected = array_values(array_filter(
			$operations,
			static fn (string $operation): bool => (bool) get_flag_value($assocArgs, $operation, false)
		));

		if (count($selected) > 1) {
			WP_CLI::error(sprintf(
				'Only one migration operation can be used at a time. Received: --%s.',
				implode(', --', $selected)
			));
		}

		return $selected[0] ?? null;
	}

	private function initializeStore(): void {
		$this->migrator->initialize();
		WP_CLI::success('Foundation migration storage is initialized.');
	}

	/**
	 * Confirm and remove only the migration ledger.
	 *
	 * @param array<string, mixed> $assocArgs
	 */
	private function dropStore(array $assocArgs): void {
		WP_CLI::confirm('Drop only the migration ledger? Application tables and shared lock storage remain, but all migrations will appear pending afterward.', $assocArgs);
		$this->migrator->dropStore();
		WP_CLI::success('The migration ledger was dropped. Application tables were not changed, and shared lock storage remains available.');
	}

	/**
	 * Confirm, roll back, and rerun all configured migrations.
	 *
	 * @param array<string, mixed> $assocArgs
	 */
	private function refreshMigrations(array $assocArgs): void {
		WP_CLI::confirm('Are you sure you want to roll back and rerun all Foundation database migrations?', $assocArgs);
		$result = $this->migrator->refresh();

		WP_CLI::success(sprintf('Rolled back %d migrations and ran %d migrations.', count($result->rolledBack), count($result->ran)));
	}

	private function rollbackMigrations(): void {
		$result = $this->migrator->rollback();

		WP_CLI::success(sprintf('Rolled back %d migrations.', count($result->rolledBack)));
	}

	private function runMigrations(): void {
		$result = $this->migrator->run();

		WP_CLI::success(sprintf('Ran %d migrations.', count($result->ran)));
	}

	private function uninitializedMessage(): string {
		$command = $this->registeredCommandName();

		if ($command === null) {
			return 'Migration storage is not initialized. Run this command with --initialize first.';
		}

		return sprintf('Migration storage is not initialized. Run `wp %s --initialize` first.', $command);
	}
}
