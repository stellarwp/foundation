<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database;

use InvalidArgumentException;
use lucatume\DI52\Container as C;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Traits\ResolvesFoundationPrefix;
use StellarWP\Foundation\Database\Cli\Migrate;
use StellarWP\Foundation\Database\Contracts\Database as DatabaseContract;
use StellarWP\Foundation\Database\Contracts\DatabaseScope;
use StellarWP\Foundation\Database\Contracts\Schema as SchemaContract;
use StellarWP\Foundation\Database\Contracts\SchemaExecutor;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Lock\DatabaseLock;
use StellarWP\Foundation\Database\Migration\Collection as MigrationCollection;
use StellarWP\Foundation\Database\Migration\Contracts\Repository;
use StellarWP\Foundation\Database\Migration\Factories\LeaseFactory;
use StellarWP\Foundation\Database\Migration\Factories\SessionFactory;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Migration\Repository as MigrationRecordRepository;
use StellarWP\Foundation\Database\Migration\Store;
use StellarWP\Foundation\Database\Schema\DbDelta;
use StellarWP\Foundation\Database\Schema\Reconciler;
use StellarWP\Foundation\Database\Scope\SiteScope;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\WPCli\WPCliProvider;

/**
 * Registers Foundation database services for WordPress environments.
 */
final class DatabaseProvider extends Provider
{
	use ResolvesFoundationPrefix;

	public const string MIGRATIONS       = self::class . '.migrations';
	public const string MIGRATIONS_TABLE = self::class . '.migrations_table';
	public const string LOCKS_TABLE      = self::class . '.locks_table';
	public const string LOCK_NAME        = self::class . '.lock_name';
	public const string LOCK_TTL         = self::class . '.lock_ttl';

	/**
	 * @throws InvalidArgumentException When the configured Foundation prefix is invalid.
	 */
	public function register(): void {
		$this->registerDatabase();
		$this->registerDatabaseScope();
		$this->registerSchema();
		$this->registerConfiguration();
		$this->registerTables();
		$this->registerMigrations();
		$this->registerLocks();
		$this->registerCliCommands();
	}

	private function registerConfiguration(): void {
		$foundationPrefix = $this->foundationPrefix();
		$databasePrefix   = str_replace('-', '_', $foundationPrefix);
		$migrationsTable  = $this->tableName(
			$this->config->get('database.migrations_table'),
			$databasePrefix . '_foundation_migrations'
		);
		$locksTable = $this->tableName(
			$this->config->get('database.locks_table'),
			$databasePrefix . '_foundation_locks'
		);
		$lockName = $this->config->get('database.lock_name')
			?? $foundationPrefix . '-foundation-database-migrations';

		$this->container->singleton(self::MIGRATIONS_TABLE, $migrationsTable);
		$this->container->singleton(self::LOCKS_TABLE, $locksTable);
		$this->container->singleton(self::LOCK_NAME, $lockName);
		$this->container->singleton(self::LOCK_TTL, (int) $this->config->get('database.lock_ttl', 300));
	}

	private function registerDatabase(): void {
		$this->container->singleton(\wpdb::class, static function (): \wpdb {
			$wpdb = $GLOBALS['wpdb'] ?? null;

			if (! $wpdb instanceof \wpdb) {
				throw new DatabaseException('The global wpdb instance is not available.');
			}

			return $wpdb;
		});
		$this->container->singleton(Database::class);
		$this->container->singleton(DatabaseContract::class, static fn (C $c): Database => $c->get(Database::class));
	}

	private function registerSchema(): void {
		$this->container->singleton(DbDelta::class);
		$this->container->singleton(SchemaExecutor::class, static fn (C $c): DbDelta => $c->get(DbDelta::class));
		$this->container->singleton(Reconciler::class);
		$this->container->singleton(Schema::class);
		$this->container->singleton(SchemaContract::class, static fn (C $c): Schema => $c->get(Schema::class));
	}

	private function registerDatabaseScope(): void {
		$this->container->singleton(SiteScope::class);
		$this->container->singleton(DatabaseScope::class, static fn (C $c): SiteScope => $c->get(SiteScope::class));
	}

	private function registerTables(): void {
		$this->container->when(MigrationTable::class)
			->needs('$unprefixedTableName')
			->give(static fn (C $c): string => $c->get(self::MIGRATIONS_TABLE));

		$this->container->when(LockTable::class)
			->needs('$unprefixedTableName')
			->give(static fn (C $c): string => $c->get(self::LOCKS_TABLE));

		$this->container->singleton(MigrationTable::class);
		$this->container->singleton(LockTable::class);
	}

	private function registerMigrations(): void {
		$this->container->mergeArrayVar(self::MIGRATIONS, []);

		$this->container->when(MigrationCollection::class)
			->needs('$migrations')
			->give(static fn (C $c): iterable => $c->get(self::MIGRATIONS));

		$this->container->when(Store::class)
			->needs('$lockName')
			->give(static fn (C $c): string => $c->get(self::LOCK_NAME));

		$this->container->when(Store::class)
			->needs('$lockTtl')
			->give(static fn (C $c): int => $c->get(self::LOCK_TTL));

		$this->container->when(Store::class)
			->needs(Lock::class)
			->give(static fn (C $c): DatabaseLock => $c->get(DatabaseLock::class));

		$this->container->singleton(MigrationCollection::class);
		$this->container->singleton(MigrationRecordRepository::class);
		$this->container->singleton(Repository::class, static fn (C $c): MigrationRecordRepository => $c->get(MigrationRecordRepository::class));
		$this->container->singleton(LeaseFactory::class);
		$this->container->singleton(SessionFactory::class);
		$this->container->singleton(Migrator::class);
	}

	private function registerLocks(): void {
		$this->container->singleton(DatabaseLock::class);
	}

	private function registerCliCommands(): void {
		$this->container->mergeArrayVar(WPCliProvider::COMMANDS, static fn (C $c): array => [
			$c->get(Migrate::class),
		]);
	}

	private function tableName(mixed $configured, string $default): string {
		return is_string($configured) && $configured !== '' ? $configured : $default;
	}
}
