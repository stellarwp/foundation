<?php declare(strict_types=1);

use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Database\Cli\Migrate;
use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Schema as SchemaContract;
use StellarWP\Foundation\Database\Database;
use StellarWP\Foundation\Database\Lock\DatabaseLock;
use StellarWP\Foundation\Database\Migration\Collection as MigrationCollection;
use StellarWP\Foundation\Database\Migration\Factories\LeaseFactory;
use StellarWP\Foundation\Database\Migration\Factories\SessionFactory;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Database\Migration\Repository;
use StellarWP\Foundation\Database\Migration\Store;
use StellarWP\Foundation\Database\Migration\StoreSchema;
use StellarWP\Foundation\Database\Schema;
use StellarWP\Foundation\Database\Schema\DbDelta;
use StellarWP\Foundation\Database\Schema\Editor;
use StellarWP\Foundation\Database\Schema\Reconciler;
use StellarWP\Foundation\Database\Scope\SiteScope;
use StellarWP\Foundation\Database\Table\Blueprint;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\TestTable;
use StellarWP\Foundation\WPCli\CommandContext;
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

	$container = (new ContainerFactory())->create(new ArrayConfiguration());

	$scope              = new SiteScope($wpdb);
	$database           = new Database($wpdb, $scope);
	$reconciler         = new Reconciler($database, new DbDelta());
	$schema             = new Schema($database, $reconciler, new Editor($database, $reconciler));
	$migrationTableName = 'foundation_cli_migrations';
	$lockTableName      = 'foundation_cli_locks';
	$exampleTable       = 'foundation_cli_example';
	$migrationTable     = new MigrationTable($migrationTableName, $database);
	$lockTable          = new LockTable($lockTableName, $database);
	$repository         = new Repository($migrationTable);
	$lock               = new DatabaseLock($database, $lockTable);
	$storeSchema        = new StoreSchema($schema, $reconciler, $migrationTable, $lockTable);
	$store              = new Store($storeSchema, new LeaseFactory(), new SessionFactory($schema), $scope, $lock, 'nx-foundation-database-migrations', 300);

	$migration = new class(new TestTable($exampleTable)) implements Migration {
		public function __construct(
			private readonly TestTable $table
		) {
		}

		public function id(): string {
			return '2026_06_23_000001_create_foundation_cli_example';
		}

		public function up(SchemaContract $schema): void {
			$blueprint = Blueprint::for($this->table);
			$blueprint->bigIncrements('id');

			$schema->create($blueprint);
		}

		public function down(SchemaContract $schema): void {
			$schema->drop($this->table);
		}
	};

	$command = new Migrate(
		new Migrator(
			new MigrationCollection([$migration]),
			$repository,
			$store
		)
	);

	$command->register(new CommandContext(new CommandPrefix('foundation')));
});
