<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Cli;

use StellarWP\Foundation\Migrations\Migrator;
use StellarWP\Foundation\WPCli\Command;
use WP_CLI;

/**
 * Reverse and reapply every migration after confirmation.
 *
 * @internal Registered by MigrationsProvider.
 */
final class RefreshCommand extends Command
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
		WP_CLI::confirm('Roll back and rerun all migrations? This can permanently delete application data.', $assocArgs);
		$this->output->showSteps($this->migrator->refresh());

		return self::SUCCESS;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function subcommand(): string {
		return 'migrate:refresh';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return 'Reverse and reapply all migrations.';
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
