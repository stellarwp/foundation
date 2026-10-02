<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use Doctrine\DBAL\Connection;
use mysqli;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;
use Throwable;
use wpdb;

/**
 * Use real private tables and independent sessions, outside WP's test transaction.
 */
abstract class DatabaseTestCase extends WPTestCase
{
	protected wpdb $source;
	protected wpdb $observerSource;
	protected Connection $db;
	protected Connection $observer;
	protected string $table;
	protected string $suffix;
	/** @var list<string> */
	protected array $tables = [];

	protected function setUp(): void {
		parent::setUp();
		$this->suffix = 'doctrine_' . bin2hex(random_bytes(5));
		$overrides    = $this->configuration();

		if ($overrides !== []) {
			$this->container = (new ContainerFactory())->create(new ArrayConfiguration(
				array_replace_recursive(require dirname(__DIR__, 3) . '/config.php', $overrides),
			));
		}
		$this->source = new wpdb(constant('DB_USER'), constant('DB_PASSWORD'), constant('DB_NAME'), constant('DB_HOST'));
		$this->source->set_prefix($GLOBALS['wpdb']->base_prefix);
		$this->source->set_blog_id(get_current_blog_id());
		$this->observerSource = new wpdb(constant('DB_USER'), constant('DB_PASSWORD'), constant('DB_NAME'), constant('DB_HOST'));
		$this->container->register(DatabaseProvider::class);
		$this->container->singleton(wpdb::class, $this->source);
		$this->db       = $this->container->get(Connection::class);
		$this->observer = (new NativeConnectionFactory())->create($this->native($this->observerSource), constant('DB_NAME'));
		$this->table    = $this->privateTable($this->suffix);
		$this->observer->executeStatement("CREATE TABLE {$this->table} (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL) ENGINE=InnoDB");
		$this->observer->insert($this->table, ['id' => 1, 'name' => 'Original']);
	}

	protected function tearDown(): void {
		try {
			try {
				$native = $this->source->__get('dbh');

				if ($native instanceof mysqli) {
					$native->rollback();
				}
			} catch (Throwable) {
				// Connection-loss tests deliberately kill or close this session.
			}
			foreach (array_reverse($this->tables) as $table) {
				$this->observer->executeStatement('DROP TABLE IF EXISTS ' . $table);
			}
			$this->observerSource->close();

			try {
				$this->source->close();
			} catch (Throwable) {
				// A failed rollback may already have closed the borrowed handle.
			}
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Configuration overrides merged over tests/config.php before providers register. The random
	 * suffix is already available, so tests can name private tables through configuration.
	 *
	 * @return array<string, mixed>
	 */
	protected function configuration(): array {
		return [];
	}

	protected function privateTable(string $name): string {
		$quoted         = $this->observer->getDatabasePlatform()->quoteSingleIdentifier($this->source->prefix . $name);
		$this->tables[] = $quoted;

		return $quoted;
	}

	protected function native(wpdb $source): mysqli {
		$native = $source->__get('dbh');
		$this->assertInstanceOf(mysqli::class, $native);

		return $native;
	}

	protected function killConnection(): void {
		$this->observer->executeStatement('KILL CONNECTION ' . $this->native($this->source)->thread_id);
	}

	protected function assertOriginal(): void {
		$this->assertSame(['Original'], $this->observer->fetchFirstColumn('SELECT name FROM ' . $this->table));
	}
}
