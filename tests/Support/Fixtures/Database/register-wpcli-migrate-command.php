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
use StellarWP\Foundation\Database\Migration\Repository;
use StellarWP\Foundation\Database\Migration\Runner;
use StellarWP\Foundation\Database\Schema;
use StellarWP\Foundation\Database\Table\Collection as TableCollection;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;

if (! class_exists(WP_CLI::class)) {
	return;
}

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

WP_CLI::add_hook('after_wp_load', static function (): void {
	$wpdb = $GLOBALS['wpdb'] ?? null;

	if (! $wpdb instanceof wpdb) {
		return;
	}

	$container = new ContainerAdapter(new DI52Container());
	$container->bind(Container::class, $container);
	$container->bind(ContainerInterface::class, $container);
	$container->singleton(Dot::class, new Dot());

	$database       = new Database($wpdb);
	$schema         = new Schema($database);
	$migrationTable = $wpdb->prefix . 'foundation_cli_migrations';
	$lockTable      = $wpdb->prefix . 'foundation_cli_locks';
	$exampleTable   = $wpdb->prefix . 'foundation_cli_example';

	$migration = new class($exampleTable) implements Migration {
		public function __construct(
			private readonly string $exampleTable
		) {
		}

		public function id(): string {
			return '2026_06_23_000001_create_foundation_cli_example';
		}

		public function up(SchemaContract $schema): void {
			$schema->createOrUpdate(sprintf(
				'CREATE TABLE %s (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				PRIMARY KEY  (id)
			);',
				$schema->quoteIdentifier($this->exampleTable)
			));
		}

		public function down(SchemaContract $schema): void {
			$schema->execute(sprintf(
				'DROP TABLE IF EXISTS %s',
				$schema->quoteIdentifier($this->exampleTable)
			));
		}
	};

	$command = new Migrate(
		$container,
		'foundation',
		new Runner(
			new Repository($database, $migrationTable),
			$schema,
			new DatabaseLock($database, $lockTable)
		),
		new MigrationCollection([$migration]),
		new TableCollection($schema, [
			new MigrationTable($database, $migrationTable),
			new LockTable($database, $lockTable),
		])
	);

	$command->register();
});
