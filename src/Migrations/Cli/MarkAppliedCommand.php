<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Cli;

use StellarWP\Foundation\Migrations\Migrator;
use StellarWP\Foundation\WPCli\Command;
use WP_CLI;

use function WP_CLI\Utils\get_flag_value;

/**
 * Record completed work without executing migration declarations.
 *
 * @internal Registered by MigrationsProvider.
 */
final class MarkAppliedCommand extends Command
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
		$id  = isset($args[0]) ? (string) $args[0] : null;
		$all = (bool) get_flag_value($assocArgs, 'all', false);

		if (($id === null && ! $all) || ($id !== null && $all)) {
			WP_CLI::error('Specify one migration ID or --all.');
		}

		if ($all) {
			WP_CLI::line('Registered migrations covered by --all (existing records keep their timestamps):');

			foreach ($this->migrator->status() as $status) {
				if ($status->migration === null) {
					continue;
				}

				WP_CLI::line($status->id . ' (' . $status->state() . ')');
			}

			WP_CLI::confirm('Record all pending migrations as applied? Confirm that their complete schema and data work already exists.', $assocArgs);
			$this->migrator->markAllApplied();
			WP_CLI::success('All registered migrations are recorded as applied. No migration work was executed.');

			return self::SUCCESS;
		}

		WP_CLI::confirm('Record ' . $id . ' as applied? Confirm that its complete schema and data work already exists.', $assocArgs);
		$this->migrator->markApplied((string) $id);
		WP_CLI::success('Recorded ' . $id . ' as applied. No migration work was executed.');

		return self::SUCCESS;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function subcommand(): string {
		return 'migrate:mark-applied';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return 'Record one migration or all pending migrations as already applied.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function arguments(): array {
		return [
			[
				'type'        => self::POSITIONAL,
				'name'        => 'id',
				'description' => 'Exact migration ID to mark; omit only with --all.',
				'optional'    => true,
			],
			[
				'type'        => self::FLAG,
				'name'        => 'all',
				'description' => 'Mark every registered pending migration applied without executing its work.',
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
