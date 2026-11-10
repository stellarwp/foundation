<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Cli;

use StellarWP\Foundation\Migrations\Migrator;
use StellarWP\Foundation\Migrations\ValueObjects\MigrationStatus;
use StellarWP\Foundation\WPCli\Command;
use WP_CLI;

use function WP_CLI\Utils\format_items;

/**
 * Display registered and recorded migration history.
 *
 * @internal Registered by MigrationsProvider.
 */
final class StatusCommand extends Command
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
	 * @throws \Throwable When reading migration history or descriptions fails.
	 */
	public function runCommand(array $args = [], array $assocArgs = []): int {
		// WP-CLI skips argument validation when a command has an empty synopsis.
		if ($args !== [] || $assocArgs !== []) {
			WP_CLI::error('migrate:status does not accept arguments or options.');
		}

		format_items('table', array_map(static fn (MigrationStatus $status): array => [
			'id'          => $status->id,
			'migration'   => $status->migration ?? '(missing)',
			'status'      => $status->state(),
			'applied_at'  => $status->appliedAt ?? '',
			'description' => $status->description,
		], $this->migrator->status()), [
			'id',
			'migration',
			'status',
			'applied_at',
			'description',
		]);

		return self::SUCCESS;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function subcommand(): string {
		return 'migrate:status';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return 'Inspect pending, applied, and missing migrations.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function arguments(): array {
		return [];
	}
}
