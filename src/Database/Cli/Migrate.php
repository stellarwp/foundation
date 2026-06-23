<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Cli;

use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Migration\Runner;
use StellarWP\Foundation\Database\Table\Collection;
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
	private const string FLAG_CREATE_TABLE = 'create-table';
	private const string FLAG_YES          = 'yes';

	/**
	 * @param iterable<Migration> $migrations
	 */
	public function __construct(
		protected Container $container,
		string $commandPrefix,
		private readonly Runner $runner,
		private readonly iterable $migrations,
		private readonly Collection $tables
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
		$createTable = (bool) get_flag_value($assocArgs, self::FLAG_CREATE_TABLE, false);

		if ($drop) {
			WP_CLI::confirm('Are you sure you want to drop the Foundation database tables? This cannot be undone.', $assocArgs);
			$this->tables->drop();
			WP_CLI::success('Foundation database tables were dropped.');

			return self::SUCCESS;
		}

		if ($createTable) {
			$this->tables->create();
			WP_CLI::success('Foundation database tables are ready.');

			return self::SUCCESS;
		}

		if ($refresh) {
			WP_CLI::confirm('Are you sure you want to roll back and rerun all Foundation database migrations?', $assocArgs);
			$this->tables->create();
			$result = $this->runner->refresh($this->migrations);
			WP_CLI::success(sprintf('Rolled back %d migrations and ran %d migrations.', count($result->rolledBack), count($result->ran)));

			return self::SUCCESS;
		}

		if ($rollback) {
			$this->tables->create();
			$result = $this->runner->rollback($this->migrations);
			WP_CLI::success(sprintf('Rolled back %d migrations.', count($result->rolledBack)));

			return self::SUCCESS;
		}

		if ($run) {
			$this->tables->create();
			$result = $this->runner->run($this->migrations);
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
				'name'        => self::FLAG_CREATE_TABLE,
				'description' => 'Create Foundation database tables without running migrations.',
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
		if (! $this->tables->exists()) {
			WP_CLI::warning('The Foundation database tables do not exist. Run this command with --create-table or --run.');

			return;
		}

		format_items('table', array_map(
			static fn ($status): array => [
				'migration' => $status->migration,
				'status'    => $status->ran ? 'ran' : 'pending',
				'batch'     => $status->batch ?? '',
				'ran_at'    => $status->ranAt?->format('Y-m-d H:i:s') ?? '',
			],
			$this->runner->status($this->migrations)
		), [
			'migration',
			'status',
			'batch',
			'ran_at',
		]);
	}
}
