<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\Database\Connection\WordPressConnection;
use StellarWP\Foundation\Database\Connection\WordPressMiddleware;
use StellarWP\Foundation\Database\Connection\WordPressSession;
use StellarWP\Foundation\Database\Contracts\AdvisorySession;
use StellarWP\Foundation\Database\Contracts\DatabaseScope;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Scope\SiteScope;
use wpdb;

/**
 * Wire a shared WordPress Doctrine connection, application tables, and transactions.
 */
final class DatabaseProvider extends Provider
{
	/**
	 * Register lazy database services without executing queries or creating storage.
	 */
	public function register(): void {
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
		$this->container->singleton(AdvisorySession::class, static fn (C $c): WordPressSession => $c->get(WordPressSession::class));
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
}
