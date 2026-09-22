<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\DriverManager;
use mysqli;

/**
 * Probe unmodified DBAL behavior using a caller-owned test mysqli session.
 */
final class NativeConnectionFactory
{
	/**
	 * Build a connection without Foundation's transaction safeguards.
	 */
	public function create(mysqli $native, string $database): Connection {
		$configuration = new Configuration();
		$configuration->setSchemaManagerFactory(new \Doctrine\DBAL\Schema\DefaultSchemaManagerFactory());
		$configuration->setMiddlewares([new class($native) implements Middleware {
			public function __construct(
				private readonly mysqli $native,
			) {
			}

			public function wrap(Driver $driver): Driver {
				return new class($driver, $this->native) extends AbstractDriverMiddleware {
					public function __construct(
						Driver $driver,
						private readonly mysqli $native,
					) {
						parent::__construct($driver);
					}

					public function connect(array $params): Driver\Connection {
						return new Driver\Mysqli\Connection($this->native);
					}
				};
			}
		}]);
		$connection = DriverManager::getConnection(['driver' => 'mysqli', 'dbname' => $database], $configuration);

		return $connection;
	}
}
