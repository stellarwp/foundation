<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database;

use InvalidArgumentException;
use lucatume\DI52\Container as C;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Traits\ResolvesFoundationPrefix;
use StellarWP\Foundation\Database\Cli\Migrate;
use StellarWP\Foundation\Database\Contracts\Database as DatabaseContract;
use StellarWP\Foundation\Database\Contracts\Repository;
use StellarWP\Foundation\Database\Contracts\Schema as SchemaContract;
use StellarWP\Foundation\Database\Contracts\SchemaExecutor;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Lock\DatabaseLock;
use StellarWP\Foundation\Database\Migration\Collection as MigrationCollection;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Migration\Repository as MigrationRecordRepository;
use StellarWP\Foundation\Database\Migration\Store;
use StellarWP\Foundation\Database\Schema\DbDelta;
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

	public const string MIGRATIONS       = 'foundation.database.migrations';
	public const string MIGRATIONS_TABLE = 'foundation.database.migrations_table';
	public const string LOCKS_TABLE      = 'foundation.database.locks_table';
	public const string LOCK_NAME        = 'foundation.database.lock_name';
	public const string LOCK_TTL         = 'foundation.database.lock_ttl';

	/**
	 * @throws InvalidArgumentException When the configured Foundation prefix is invalid.
	 */
	public function register(): void {
		$this->registerConfiguration();
		$this->registerDatabase();
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

		$this->container->mergeArrayVar(self::MIGRATIONS, []);
		$this->container->singleton(self::MIGRATIONS_TABLE, $migrationsTable);
		$this->container->singleton(self::LOCKS_TABLE, $locksTable);
		$this->container->singleton(self::LOCK_NAME, $lockName);
		$this->container->singleton(self::LOCK_TTL, (int) $this->config->get('database.lock_ttl', 300));
	}

	private function registerDatabase(): void {
		$this->container->singleton(Database::class, static function (): Database {
			$wpdb = $GLOBALS['wpdb'] ?? null;

			if (! $wpdb instanceof \wpdb) {
				throw new DatabaseException('The global wpdb instance is not available.');
			}

			return new Database($wpdb);
		});
		$this->container->singleton(DatabaseContract::class, static fn (C $c): Database => $c->get(Database::class));
		$this->container->singleton(DbDelta::class);
		$this->container->singleton(SchemaExecutor::class, static fn (C $c): DbDelta => $c->get(DbDelta::class));
		$this->container->singleton(Schema::class);
		$this->container->singleton(SchemaContract::class, static fn (C $c): Schema => $c->get(Schema::class));
	}

	private function registerTables(): void {
		$this->container->when(MigrationTable::class)
			->needs('$table')
			->give(static fn (C $c): string => $c->get(self::MIGRATIONS_TABLE));

		$this->container->when(LockTable::class)
			->needs('$table')
			->give(static fn (C $c): string => $c->get(self::LOCKS_TABLE));

		$this->container->singleton(MigrationTable::class);
		$this->container->singleton(LockTable::class);
	}

	private function registerMigrations(): void {
		$this->container->when(MigrationCollection::class)
			->needs('$migrations')
			->give(static fn (C $c): iterable => $c->get(self::MIGRATIONS));

		$this->container->when(MigrationRecordRepository::class)
			->needs('$table')
			->give(static fn (C $c): string => $c->get(self::MIGRATIONS_TABLE));

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
		$this->container->singleton(Migrator::class);
	}

	private function registerLocks(): void {
		$this->container->when(DatabaseLock::class)
			->needs('$table')
			->give(static fn (C $c): string => $c->get(self::LOCKS_TABLE));

		$this->container->singleton(DatabaseLock::class);
	}

	private function registerCliCommands(): void {
		$this->container->when(Migrate::class)
			->needs('$commandPrefix')
			->give(static fn (C $c): string => $c->get(WPCliProvider::COMMAND_PREFIX));

		$this->container->mergeArrayVar(WPCliProvider::COMMANDS, static fn (C $c): array => [
			$c->get(Migrate::class),
		]);
	}

	private function tableName(mixed $configured, string $default): mixed {
		if (is_string($configured) && $configured !== '') {
			return $configured;
		}

		return static fn (C $c): string => $c->get(DatabaseContract::class)->tableName($default);
	}
}
