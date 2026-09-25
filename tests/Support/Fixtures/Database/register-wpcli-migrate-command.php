<?php declare(strict_types=1);

use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Migrations\Contracts\Migration;
use StellarWP\Foundation\Migrations\MigrationsProvider;
use StellarWP\Foundation\Migrations\Schema\Blueprint;
use StellarWP\Foundation\Migrations\ValueObjects\MigrationRegistration;
use StellarWP\Foundation\WPCli\CommandContext;
use StellarWP\Foundation\WPCli\ValueObjects\CommandPrefix;
use StellarWP\Foundation\WPCli\WPCliProvider;

if (! class_exists(WP_CLI::class)) {
	return;
}

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

WP_CLI::add_hook('after_wp_load', static function (): void {
	$container = (new ContainerFactory())->create(new ArrayConfiguration([
		'migrations' => [
			'table' => 'foundation_cli_migrations',
		],
	]));
	$container->register(DatabaseProvider::class);
	$container->register(MigrationsProvider::class);
	$table = new class implements Table {
		/**
		 * Return the fixture's stable application table name.
		 */
		public function unprefixedName(): string {
			return 'foundation_cli_example';
		}
	};
	$migration = new class($table) implements Migration {
		/**
		 * Receive the application table identity.
		 */
		public function __construct(
			private readonly Table $table,
		) {
		}

		/**
		 * Declare the initial application table.
		 */
		public function up(Blueprint $schema): void {
			$schema->create($this->table)->bigIncrements();
		}

		/**
		 * Remove the fixture's application table.
		 */
		public function down(Blueprint $schema): void {
			$schema->drop($this->table);
		}
	};
	$container->mergeArrayVar(MigrationsProvider::MIGRATIONS, [
		new MigrationRegistration('20260623000001', $migration),
	]);
	$context = new CommandContext(new CommandPrefix('foundation'));

	// WP-CLI is already loaded; consume the real provider contributions at this hook.
	foreach ($container->get(WPCliProvider::COMMANDS) as $command) {
		$command->register($context);
	}
});
