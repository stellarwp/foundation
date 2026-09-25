<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Cli;

use StellarWP\Foundation\Migrations\Migrator;
use StellarWP\Foundation\WPCli\Command;
use WP_CLI;

use function WP_CLI\Utils\get_flag_value;

/**
 * Apply pending changes or preview their SQL.
 *
 * @internal Registered by MigrationsProvider.
 */
final class RunCommand extends Command
{
	/**
	 * Receive the migration service and shared step presentation.
	 */
	public function __construct(
		private readonly Migrator $migrator,
		private readonly MigrationOutput $output,
	) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \Throwable When migration validation, execution, or history storage fails.
	 */
	public function runCommand(array $args = [], array $assocArgs = []): int {
		$target = (string) ($assocArgs['to'] ?? Migrator::LATEST);
		$dryRun = (bool) get_flag_value($assocArgs, 'dry-run', false);

		if (! $dryRun && $target === Migrator::NONE) {
			WP_CLI::confirm('Roll back all migrations? This can permanently delete application data.', $assocArgs);
		}

		$steps = $dryRun ? $this->migrator->preview($target) : $this->migrator->migrate($target);
		$this->output->showSteps($steps, $dryRun);

		return self::SUCCESS;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function subcommand(): string {
		return 'migrate:run';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return 'Apply pending migrations, or reconcile to a target ID.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function arguments(): array {
		return [
			[
				'type'        => self::ASSOCIATIVE,
				'name'        => 'to',
				'description' => 'Target migration ID, 0 for none, or latest for all.',
				'optional'    => true,
			],
			[
				'type'        => self::FLAG,
				'name'        => 'dry-run',
				'description' => 'Preview SQL without executing SQL or data callbacks.',
				'optional'    => true,
			],
			[
				'type'        => self::FLAG,
				'name'        => 'yes',
				'description' => 'Confirm without prompting.',
				'optional'    => true,
			],
		];
	}
}
