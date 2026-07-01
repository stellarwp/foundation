<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Cli;

use StellarWP\Foundation\Container\Contracts\Container;
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
	private const string FLAG_RUN          = 'run';
	private const string FLAG_ROLLBACK     = 'rollback';
	private const string FLAG_REFRESH      = 'refresh';
	private const string FLAG_DROP         = 'drop';
	private const string FLAG_PREPARE      = 'prepare';
	private const string FLAG_CREATE_TABLE = 'create-table';
	private const string FLAG_YES          = 'yes';

	public function __construct(
		protected Container $container,
		string $commandPrefix,
		private readonly Migrator $migrator
	) {
		parent::__construct($this->container, $commandPrefix);
	}

	/**
	 * @param list<mixed>         $args
	 * @param array<string,mixed> $assocArgs
	 */
	public function runCommand(array $args = [], array $assocArgs = []): int {
		$run         = (bool) get_flag_value($assocArgs, self::FLAG_RUN, false);
		$rollback    = (bool) get_flag_value($assocArgs, self::FLAG_ROLLBACK, false);
		$refresh     = (bool) get_flag_value($assocArgs, self::FLAG_REFRESH, false);
		$drop        = (bool) get_flag_value($assocArgs, self::FLAG_DROP, false);
		$prepare     = (bool) get_flag_value($assocArgs, self::FLAG_PREPARE, false);
		$createTable = (bool) get_flag_value($assocArgs, self::FLAG_CREATE_TABLE, false);

		if (! $this->hasSingleOperation([
			self::FLAG_RUN      => $run,
			self::FLAG_ROLLBACK => $rollback,
			self::FLAG_REFRESH  => $refresh,
			self::FLAG_DROP     => $drop,
			self::FLAG_PREPARE  => $prepare || $createTable,
		])) {
			return self::ERROR;
		}

		if ($drop) {
			WP_CLI::confirm('Are you sure you want to drop the Foundation database tables? This cannot be undone.', $assocArgs);
			$this->migrator->drop();
			WP_CLI::success('Foundation database tables were dropped.');

			return self::SUCCESS;
		}

		if ($prepare || $createTable) {
			$this->migrator->prepare();
			WP_CLI::success('Foundation database tables are ready.');

			return self::SUCCESS;
		}

		if ($refresh) {
			WP_CLI::confirm('Are you sure you want to roll back and rerun all Foundation database migrations?', $assocArgs);
			$result = $this->migrator->refresh();
			WP_CLI::success(sprintf('Rolled back %d migrations and ran %d migrations.', count($result->rolledBack), count($result->ran)));

			return self::SUCCESS;
		}

		if ($rollback) {
			$result = $this->migrator->rollback();
			WP_CLI::success(sprintf('Rolled back %d migrations.', count($result->rolledBack)));

			return self::SUCCESS;
		}

		if ($run) {
			$result = $this->migrator->run();
			WP_CLI::success(sprintf('Ran %d migrations.', count($result->ran)));

			return self::SUCCESS;
		}

		$this->showStatus();

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
				'name'        => self::FLAG_DROP,
				'description' => 'Drop Foundation database tables.',
				'optional'    => true,
				'default'     => false,
			],
			[
				'type'        => self::FLAG,
				'name'        => self::FLAG_PREPARE,
				'description' => 'Prepare Foundation migration storage without running migrations.',
				'optional'    => true,
				'default'     => false,
			],
			[
				'type'        => self::FLAG,
				'name'        => self::FLAG_CREATE_TABLE,
				'description' => 'Alias for --prepare.',
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
		if (! $this->migrator->exists()) {
			WP_CLI::warning('The Foundation database tables do not exist. Run this command with --prepare or --run.');
		}

		format_items('table', array_map(
			static fn ($status): array => [
				'migration' => $status->migration,
				'status'    => $status->ran ? 'ran' : 'pending',
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
	 * @param array<string, bool> $operations
	 */
	private function hasSingleOperation(array $operations): bool {
		$selected = array_keys(array_filter($operations));

		if (count($selected) <= 1) {
			return true;
		}

		WP_CLI::error(sprintf(
			'Only one migration operation can be used at a time. Received: --%s.',
			implode(', --', $selected)
		), false);

		return false;
	}
}
