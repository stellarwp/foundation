<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations;

use Doctrine\DBAL\Schema\ComparatorConfig;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\Container\Traits\ResolvesFoundationPrefix;
use StellarWP\Foundation\Migrations\Cli\Migrate;
use StellarWP\Foundation\Migrations\Schema\Factories\MigrationComparatorFactory;
use StellarWP\Foundation\Migrations\Schema\RenamePlanner;
use StellarWP\Foundation\Migrations\Schema\Renames\Contracts\ColumnRename;
use StellarWP\Foundation\Migrations\Schema\Renames\PlatformColumnRename;
use StellarWP\Foundation\Migrations\Schema\SchemaPlanner;
use StellarWP\Foundation\Migrations\Tables\MigrationTable;
use StellarWP\Foundation\WPCli\WPCliProvider;
use wpdb;

/**
 * Wire migration discovery, schema planning, history, and the WordPress migration command.
 */
final class MigrationsProvider extends Provider
{
	use ResolvesFoundationPrefix;

	public const string MIGRATIONS                   = self::class . '.migrations';
	private const string MIGRATION_COMPARATOR_CONFIG = self::class . '.migration_comparator_config';

	/**
	 * Register migrations after DatabaseProvider, without querying or creating storage.
	 *
	 * @throws \InvalidArgumentException When the configured resource prefix is invalid.
	 */
	public function register(): void {
		$this->registerTables();
		$this->registerMigrations();

		$this->container->mergeArrayVar(WPCliProvider::COMMANDS, static fn (C $c): array => [
			$c->get(Migrate::class),
		]);
	}

	private function registerTables(): void {
		$prefix = str_replace('-', '_', $this->foundationPrefix());

		$this->container->when(MigrationTable::class)
			->needs('$unprefixedTableName')
			->give($this->config->get('migrations.table', $prefix . '_foundation_migrations'));

		$this->container->singleton(MigrationTable::class);
	}

	private function registerMigrations(): void {
		$this->container->when(MigrationDiscovery::class)
			->needs('$root')
			->give(fn (): ?string => $this->config->get('foundation.root'));

		$this->container->when(MigrationDiscovery::class)
			->needs('$path')
			->give(fn (): ?string => $this->config->get('migrations.path'));

		$this->container->singleton(MigrationDiscovery::class);
		$this->container->mergeArrayVar(
			self::MIGRATIONS,
			static fn (C $c): array => iterator_to_array($c->get(MigrationDiscovery::class)->migrations(), false)
		);

		$this->container->when(MigrationCollection::class)
			->needs('$migrations')
			->give(static fn (C $c): iterable => $c->get(self::MIGRATIONS));

		$this->container->singleton(
			self::MIGRATION_COMPARATOR_CONFIG,
			static fn (): ComparatorConfig => (new ComparatorConfig())
				->withReportModifiedIndexes(false)
				->withDetectRenamedColumns(false),
		);

		$this->container->when(MigrationComparatorFactory::class)
			->needs(ComparatorConfig::class)
			->give(static fn (C $c): ComparatorConfig => $c->get(self::MIGRATION_COMPARATOR_CONFIG));

		$this->container->when(SchemaPlanner::class)
			->needs('$tableOptions')
			->give(static function (C $c): array {
				$source = $c->get(wpdb::class);

				return array_filter([
					'engine'    => 'InnoDB',
					'charset'   => $source->charset,
					'collation' => $source->collate,
				]);
			});

		$this->container->singleton(MigrationCollection::class);
		$this->container->singleton(History::class);
		$this->container->singleton(MigrationComparatorFactory::class);
		$this->container->singleton(ColumnRename::class, PlatformColumnRename::class);
		$this->container->singleton(RenamePlanner::class);
		$this->container->singleton(SchemaPlanner::class);
		$this->container->singleton(Migrator::class);
	}
}
