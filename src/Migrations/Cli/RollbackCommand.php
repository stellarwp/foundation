<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Cli;

use StellarWP\Foundation\Migrations\Migrator;
use StellarWP\Foundation\WPCli\Command;
use WP_CLI;

/**
 * Reverse selected applied migrations.
 *
 * @internal Registered by MigrationsProvider.
 */
final class RollbackCommand extends Command
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
		if (isset($assocArgs['step'], $assocArgs['to'])) {
			WP_CLI::error('--step cannot be combined with --to.');
		}

		$steps = filter_var($assocArgs['step'] ?? 1, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 1,
			],
		]);

		if ($steps === false) {
			WP_CLI::error('--step must be a positive integer.');

			return self::ERROR;
		}

		$target = (string) ($assocArgs['to'] ?? Migrator::LATEST);

		if ($target === Migrator::NONE) {
			WP_CLI::confirm('Roll back all migrations? This can permanently delete application data.', $assocArgs);
		}

		$result = isset($assocArgs['to']) ? $this->migrator->rollbackTo($target) : $this->migrator->rollback($steps);
		$this->output->showSteps($result);

		return self::SUCCESS;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function subcommand(): string {
		return 'migrate:rollback';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return 'Reverse the highest applied ID, several steps, or IDs above a target.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function arguments(): array {
		return [
			[
				'type'        => self::ASSOCIATIVE,
				'name'        => 'step',
				'description' => 'Positive number of applied IDs to reverse; defaults to one.',
				'optional'    => true,
			],
			[
				'type'        => self::ASSOCIATIVE,
				'name'        => 'to',
				'description' => 'Reverse applied IDs above this target; 0 reverses all.',
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
