<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Cli;

use StellarWP\Foundation\Migrations\Migrator;
use StellarWP\Foundation\WPCli\Command;
use WP_CLI;

/**
 * Remove an applied history record after manual reversal.
 *
 * @internal Registered by MigrationsProvider.
 */
final class MarkPendingCommand extends Command
{
	/**
	 * Receive the migration service.
	 */
	public function __construct(
		private readonly Migrator $migrator,
	) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \Throwable When identity validation, history storage, or lock ownership fails.
	 */
	public function runCommand(array $args = [], array $assocArgs = []): int {
		$id = (string) $args[0];
		WP_CLI::confirm('Remove the applied record for ' . $id . '? Its work must already be undone; a registered migration can run again.', $assocArgs);
		$this->migrator->markPending($id);
		WP_CLI::success('Removed the applied record for ' . $id . '. No migration work was executed.');

		return self::SUCCESS;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function subcommand(): string {
		return 'migrate:mark-pending';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return 'Remove the applied history record for one migration.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function arguments(): array {
		return [
			[
				'type'        => self::POSITIONAL,
				'name'        => 'id',
				'description' => 'Exact migration ID whose applied record should be removed.',
				'optional'    => false,
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
