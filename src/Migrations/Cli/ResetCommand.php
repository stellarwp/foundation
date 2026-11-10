<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Cli;

use StellarWP\Foundation\Migrations\Migrator;
use StellarWP\Foundation\WPCli\Command;
use WP_CLI;

/**
 * Reverse every applied migration after confirmation.
 *
 * @internal Registered by MigrationsProvider.
 */
final class ResetCommand extends Command
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
		WP_CLI::confirm('Roll back all migrations? This can permanently delete application data.', $assocArgs);
		$this->output->showSteps($this->migrator->rollbackTo(Migrator::NONE));

		return self::SUCCESS;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function subcommand(): string {
		return 'migrate:reset';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return 'Reverse all applied migrations without rerunning them.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function arguments(): array {
		return [
			[
				'type'        => self::FLAG,
				'name'        => 'yes',
				'description' => 'Confirm without prompting.',
				'optional'    => true,
			],
		];
	}
}
