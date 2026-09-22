<?php declare(strict_types=1);

use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Database\Cli\Migrate;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Database\Migration\Contracts\Migration;
use StellarWP\Foundation\Database\Migration\Schema\Blueprint;
use StellarWP\Foundation\WPCli\CommandContext;
use StellarWP\Foundation\WPCli\ValueObjects\CommandPrefix;

if (! class_exists(WP_CLI::class)) {
	return;
}
require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

WP_CLI::add_hook('after_wp_load', static function (): void {
	$container = (new ContainerFactory())->create(new ArrayConfiguration([
		'database' => ['migrations_table' => 'foundation_cli_migrations'],
	]));
	$container->register(DatabaseProvider::class);
	$table = new class implements Table {
		public function unprefixedName(): string {
			return 'foundation_cli_example';
		}
	};
	$migration = new class($table) implements Migration {
		public function __construct(
			private readonly Table $table,
		) {
		}

		public function id(): string {
			return '20260623000001';
		}

		public function up(Blueprint $schema): void {
			$schema->create($this->table)->bigIncrements();
		}

		public function down(Blueprint $schema): void {
			$schema->drop($this->table);
		}
	};
	$container->mergeArrayVar(DatabaseProvider::MIGRATIONS, [$migration]);
	$container->get(Migrate::class)->register(new CommandContext(new CommandPrefix('foundation')));
});
