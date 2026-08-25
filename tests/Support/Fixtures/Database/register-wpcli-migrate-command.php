<?php declare(strict_types=1);

use Adbar\Dot;
use lucatume\DI52\Container as DI52Container;
use StellarWP\ContainerContract\ContainerInterface;
use StellarWP\Foundation\Container\ContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Database\Cli\Migrate;
use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Schema as SchemaContract;
use StellarWP\Foundation\Database\Database;
use StellarWP\Foundation\Database\Lock\DatabaseLock;
use StellarWP\Foundation\Database\Migration\Collection as MigrationCollection;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Migration\Repository;
use StellarWP\Foundation\Database\Migration\Store;
use StellarWP\Foundation\Database\Schema;
use StellarWP\Foundation\Database\Schema\DbDelta;
use StellarWP\Foundation\Database\Schema\Reconciler;
use StellarWP\Foundation\Database\Scope\SiteScope;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestTable;
use StellarWP\Foundation\WPCli\ValueObjects\CommandPrefix;

if (! class_exists(WP_CLI::class)) {
	return;
}

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

WP_CLI::add_hook('after_wp_load', static function (): void {
	$wpdb = $GLOBALS['wpdb'] ?? null;

	if (! $wpdb instanceof wpdb) {
		return;
	}

	if (! defined('ABSPATH')) {
		return;
	}

	$container = new ContainerAdapter(new DI52Container());
	$container->bind(Container::class, $container);
	$container->bind(ContainerInterface::class, $container);
	$container->singleton(Dot::class, new Dot());

	$scope              = new SiteScope($wpdb);
	$database           = new Database($wpdb, $scope);
	$schema             = new Schema($database, new Reconciler($database, new DbDelta()));
	$migrationTableName = 'foundation_cli_migrations';
	$lockTableName      = 'foundation_cli_locks';
	$exampleTable       = $wpdb->prefix . 'foundation_cli_example';
	$migrationTable     = new MigrationTable($migrationTableName, $database);
	$lockTable          = new LockTable($lockTableName, $database);
	$repository         = new Repository($database, $migrationTable);
	$lock               = new DatabaseLock($database, $lockTable);
	$store              = new Store($schema, $scope, $lock, $migrationTable, $lockTable);

	$migration = new class(new TestTable('foundation_cli_example', $exampleTable)) implements Migration {
		public function __construct(
			private readonly TestTable $table
		) {
		}

		public function id(): string {
			return '2026_06_23_000001_create_foundation_cli_example';
		}

		public function up(SchemaContract $schema): void {
			$schema->createOrUpdate($this->table);
		}

		public function down(SchemaContract $schema): void {
			$schema->execute(sprintf(
				'DROP TABLE IF EXISTS %s',
				$schema->quoteIdentifier($this->table->name())
			));
		}
	};

	$command = new Migrate(
		$container,
		new CommandPrefix('foundation'),
		new Migrator(
			new MigrationCollection([$migration]),
			$repository,
			$store
		)
	);

	$command->register();
});
