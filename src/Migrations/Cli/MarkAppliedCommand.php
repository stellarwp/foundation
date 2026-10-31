<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Cli;

use StellarWP\Foundation\Migrations\Migrator;
use StellarWP\Foundation\Migrations\ValueObjects\MigrationStatus;
use StellarWP\Foundation\WPCli\Command;
use WP_CLI;

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
		$id     = $args[0] ?? null;
		$all    = (bool) ($assocArgs['all'] ?? false);
		$target = $assocArgs['to'] ?? null;

		if ((int) ($id !== null) + (int) $all + (int) ($target !== null) !== 1) {
			WP_CLI::error('Specify exactly one migration ID, --all, or --to=<id>.');
		}

		if ($id !== null) {
			WP_CLI::confirm('Record ' . $id . ' as applied? Confirm that its complete schema and data work already exists.', $assocArgs);
			$this->migrator->markApplied($id);
			WP_CLI::success('Recorded ' . $id . ' as applied. No migration work was executed.');

			return self::SUCCESS;
		}

		$registered = array_filter(
			$this->migrator->status(),
			static fn (MigrationStatus $status): bool => $status->migration !== null,
		);

		if ($target !== null && ! in_array($target, array_column($registered, 'id'), true)) {
			WP_CLI::error('Unknown registered migration target: ' . $target);
		}

		$selection = $target === null ? '--all' : '--to=' . $target;
		WP_CLI::line('Registered migrations covered by ' . $selection . ' (existing records keep their timestamps):');

		foreach ($registered as $status) {
			if ($target !== null && strcmp($status->id, $target) > 0) {
				continue;
			}

			WP_CLI::line($status->id . ' (' . $status->state() . ')');
		}

		if ($target === null) {
			WP_CLI::confirm('Record all pending migrations as applied? Confirm that their complete schema and data work already exists.', $assocArgs);
			$this->migrator->markAllApplied();
			WP_CLI::success('All registered migrations are recorded as applied. No migration work was executed.');

			return self::SUCCESS;
		}

		WP_CLI::confirm('Record pending migrations through ' . $target . ' as applied? Confirm that their complete schema and data work already exists.', $assocArgs);
		$this->migrator->markAppliedThrough($target);
		WP_CLI::success('Registered migrations through ' . $target . ' are recorded as applied. No migration work was executed.');

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
		return 'Record one migration, all pending migrations, or pending migrations through a target as already applied.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function arguments(): array {
		return [
			[
				'type'        => self::POSITIONAL,
				'name'        => 'id',
				'description' => 'Exact migration ID to mark; omit with --all or --to.',
				'optional'    => true,
			],
			[
				'type'        => self::FLAG,
				'name'        => 'all',
				'description' => 'Mark every registered pending migration applied without executing its work.',
				'optional'    => true,
			],
			[
				'type'        => self::ASSOCIATIVE,
				'name'        => 'to',
				'description' => 'Mark pending migrations through this registered ID, inclusively, without executing their work.',
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
