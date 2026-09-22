<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\Container\Traits\ResolvesFoundationPrefix;
use StellarWP\Foundation\Database\Cli\Migrate;
use StellarWP\Foundation\Database\Connection\WordPressConnection;
use StellarWP\Foundation\Database\Connection\WordPressMiddleware;
use StellarWP\Foundation\Database\Connection\WordPressSession;
use StellarWP\Foundation\Database\Contracts\DatabaseScope;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Lock\DatabaseLock;
use StellarWP\Foundation\Database\Migration\History;
use StellarWP\Foundation\Database\Migration\MigrationCollection;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Migration\Schema\SchemaPlanner;
use StellarWP\Foundation\Database\Scope\SiteScope;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\WPCli\WPCliProvider;
use wpdb;

/**
 * Wire a shared WordPress Doctrine connection, application tables, and migrations.
 */
final class DatabaseProvider extends Provider
{
	public const string MIGRATIONS = self::class . '.migrations';
	use ResolvesFoundationPrefix;

	/**
	 * Register lazy database services without executing queries or creating storage.
	 *
	 * @throws \InvalidArgumentException When the configured resource prefix is invalid.
	 */
	public function register(): void {
		$this->registerConnection();
		$this->registerTables();
		$this->registerMigrations();
		$this->container->singleton(DatabaseLock::class);
		$this->container->mergeArrayVar(WPCliProvider::COMMANDS, static fn (C $c): array => [$c->get(Migrate::class)]);
	}

	private function registerConnection(): void {
		$this->container->singleton(wpdb::class, static function (): wpdb {
			$source = $GLOBALS['wpdb'] ?? null;

			if (! $source instanceof wpdb) {
				throw new DatabaseException('The global wpdb instance is not available.');
			}

			return $source;
		});
		$this->container->singleton(DatabaseScope::class, SiteScope::class);
		$this->container->singleton(TableNameResolver::class, Table\TableNameResolver::class);
		$this->container->singleton(WordPressSession::class);
		$this->container->singleton(Connection::class, static function (C $c): Connection {
			$config = new Configuration();
			$config->setSchemaManagerFactory(new DefaultSchemaManagerFactory());
			$config->setMiddlewares([new WordPressMiddleware($c->get(WordPressSession::class))]);

			return DriverManager::getConnection([
				'driver'       => 'mysqli',
				'wrapperClass' => WordPressConnection::class,
			], $config);
		});
	}

	private function registerTables(): void {
		$prefix = str_replace('-', '_', $this->foundationPrefix());
		$this->container->when(MigrationTable::class)->needs('$unprefixedTableName')
			->give($this->config->get('database.migrations_table', $prefix . '_foundation_migrations'));
		$this->container->when(LockTable::class)->needs('$unprefixedTableName')
			->give($this->config->get('database.locks_table', $prefix . '_foundation_locks'));
		$this->container->singleton(MigrationTable::class);
		$this->container->singleton(LockTable::class);
	}

	private function registerMigrations(): void {
		$this->container->mergeArrayVar(self::MIGRATIONS, []);
		$this->container->when(MigrationCollection::class)->needs('$migrations')
			->give(static fn (C $c): iterable => $c->get(self::MIGRATIONS));
		$this->container->when(SchemaPlanner::class)->needs('$tableOptions')->give(static function (C $c): array {
			$source = $c->get(wpdb::class);

			return array_filter(['engine' => 'InnoDB', 'charset' => $source->charset, 'collation' => $source->collate]);
		});
		$this->container->singleton(MigrationCollection::class);
		$this->container->singleton(History::class);
		$this->container->singleton(SchemaPlanner::class);
		$this->container->singleton(Migrator::class);
	}
}
