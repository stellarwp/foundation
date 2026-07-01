<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Commands\Make;

use PhpParser\Lexer;
use PhpParser\ParserFactory;
use StellarWP\Foundation\Cli\Commands\Make\Database\MigrationCommand;
use StellarWP\Foundation\Cli\Commands\Make\Database\ProviderCommand;
use StellarWP\Foundation\Cli\Commands\Make\Database\ProviderRegistrationEditor;
use StellarWP\Foundation\Cli\Commands\Make\Database\TableCommand;
use StellarWP\Foundation\Cli\Generation\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
use StellarWP\Foundation\Cli\Generation\Php\PhpSourceEditor;
use StellarWP\Foundation\Cli\Generation\StubRenderer;
use StellarWP\Foundation\Cli\Generation\StubResolver;
use StellarWP\Foundation\Cli\Generation\WordPressClassNameResolver;
use StellarWP\Foundation\Tests\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DatabaseCommandTest extends TestCase
{
	/**
	 * @var list<string>
	 */
	private array $temporaryRoots = [];

	private string $tempDir;

	protected function setUp(): void {
		parent::setUp();

		$this->tempDir = $this->prepare_temp_dir('make-database-command');
	}

	protected function tearDown(): void {
		foreach ($this->temporaryRoots as $temporaryRoot) {
			$this->removeDirectory($temporaryRoot);
		}

		parent::tearDown();
	}

	public function test_it_generates_a_database_table_from_project_autoload_defaults(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->tableCommand($root));

		$statusCode = $tester->execute([
			'name' => 'reports',
		]);

		$path = $root . '/src/Database/Tables/Reports_Table.php';

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertFileExists($path);
		$this->assertStringContainsString('Created: src/Database/Tables/Reports_Table.php', $tester->getDisplay());

		$contents = (string) file_get_contents($path);

		$this->assertStringContainsString('namespace Acme\\Plugin\\Database\\Tables;', $contents);
		$this->assertStringContainsString('use StellarWP\\Foundation\\Database\\Contracts\\Database;', $contents);
		$this->assertStringContainsString('use StellarWP\\Foundation\\Database\\Contracts\\Table;', $contents);
		$this->assertStringContainsString('use StellarWP\\Foundation\\Database\\Table\\TableDefinition;', $contents);
		$this->assertStringContainsString('final readonly class Reports_Table implements Table {', $contents);
		$this->assertStringContainsString("public const string ID    = 'reports_table';", $contents);
		$this->assertStringContainsString("public const string TABLE = 'reports';", $contents);
		$this->assertStringContainsString('return $this->database->tableName( self::TABLE );', $contents);
		$this->assertStringContainsString("->longText( 'payload' )", $contents);
	}

	public function test_it_generates_a_database_migration_from_project_autoload_defaults(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute([
			'name' => 'create-reports-table',
			'--id' => '2026_06_26_000001_create_reports_table',
		]);

		$path = $root . '/src/Database/Migrations/Create_Reports_Table.php';

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertFileExists($path);
		$this->assertStringContainsString('Created: src/Database/Migrations/Create_Reports_Table.php', $tester->getDisplay());

		$contents = (string) file_get_contents($path);

		$this->assertStringContainsString('namespace Acme\\Plugin\\Database\\Migrations;', $contents);
		$this->assertStringContainsString('use StellarWP\\Foundation\\Database\\Contracts\\Migration;', $contents);
		$this->assertStringContainsString('use StellarWP\\Foundation\\Database\\Contracts\\Schema;', $contents);
		$this->assertStringContainsString('use StellarWP\\Foundation\\Database\\Table\\CreateTable;', $contents);
		$this->assertStringContainsString('use Acme\\Plugin\\Database\\Tables\\Reports_Table;', $contents);
		$this->assertStringContainsString('final readonly class Create_Reports_Table implements Migration {', $contents);
		$this->assertStringContainsString("public const string ID = '2026_06_26_000001_create_reports_table';", $contents);
		$this->assertStringContainsString('private Reports_Table $table', $contents);
		$this->assertStringContainsString('( new CreateTable( $this->table ) )->up( $schema );', $contents);
	}

	public function test_it_generates_a_generic_database_migration_for_non_table_names(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute([
			'name' => 'bump-version',
			'--id' => '2026_06_26_000003_bump_version',
		]);

		$contents = (string) file_get_contents($root . '/src/Database/Migrations/Bump_Version.php');

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('final readonly class Bump_Version implements Migration {', $contents);
		$this->assertStringContainsString('use StellarWP\\Foundation\\Database\\Exceptions\\IrreversibleMigration;', $contents);
		$this->assertStringContainsString("public const string ID = '2026_06_26_000003_bump_version';", $contents);
		$this->assertStringContainsString('throw new IrreversibleMigration( self::ID );', $contents);
		$this->assertStringNotContainsString('CreateTable', $contents);
		$this->assertStringNotContainsString('Bump_Version_Table', $contents);
	}

	public function test_it_generates_a_database_provider_from_project_autoload_defaults(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->providerCommand($root));

		$statusCode = $tester->execute([]);

		$path = $root . '/src/Database/Provider.php';

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertFileExists($path);
		$this->assertStringContainsString('Created: src/Database/Provider.php', $tester->getDisplay());

		$contents = (string) file_get_contents($path);

		$this->assertStringContainsString('namespace Acme\\Plugin\\Database;', $contents);
		$this->assertStringContainsString('use lucatume\\DI52\\Container as C;', $contents);
		$this->assertStringContainsString('use StellarWP\\Foundation\\Database\\DatabaseProvider;', $contents);
		$this->assertStringContainsString('use StellarWP\\Foundation\\Container\\Contracts\\Provider as Service_Provider;', $contents);
		$this->assertStringContainsString('final class Provider extends Service_Provider {', $contents);
		$this->assertStringContainsString('$this->register_tables();', $contents);
		$this->assertStringContainsString('$this->register_migrations();', $contents);
		$this->assertStringContainsString('// foundation:database-tables', $contents);
		$this->assertStringNotContainsString('// foundation:database-migrations', $contents);
	}

	public function test_database_migrations_default_to_timestamped_ids(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute([
			'name' => 'create-reports-table',
		]);

		$contents = (string) file_get_contents($root . '/src/Database/Migrations/Create_Reports_Table.php');

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertMatchesRegularExpression(
			"/public const string ID = '\\d{4}_\\d{2}_\\d{2}_\\d{6}_create_reports_table';/",
			$contents
		);
	}

	public function test_database_generators_accept_generation_options(): void {
		$root = $this->temporaryProject();

		$tableTester = new CommandTester($this->tableCommand($root));
		$tableStatus = $tableTester->execute([
			'name'        => 'Audit_Log',
			'--namespace' => 'Acme\\Plugin\\Storage',
			'--path'      => 'custom/tables',
			'--id'        => 'audit_log_storage',
			'--table'     => 'custom_audit_log',
		]);

		$migrationTester = new CommandTester($this->migrationCommand($root));
		$migrationStatus = $migrationTester->execute([
			'name'              => 'Create_Audit_Log_Table',
			'--namespace'       => 'Acme\\Plugin\\Storage\\Migrations',
			'--path'            => 'custom/migrations',
			'--id'              => '2026_06_26_000002_create_audit_log_table',
			'--table-class'     => 'Audit_Log',
			'--table-namespace' => 'Acme\\Plugin\\Storage',
		]);

		$tableContents     = (string) file_get_contents($root . '/custom/tables/Audit_Log_Table.php');
		$migrationContents = (string) file_get_contents($root . '/custom/migrations/Create_Audit_Log_Table.php');

		$this->assertSame(Command::SUCCESS, $tableStatus);
		$this->assertStringContainsString('namespace Acme\\Plugin\\Storage;', $tableContents);
		$this->assertStringContainsString("public const string ID    = 'audit_log_storage';", $tableContents);
		$this->assertStringContainsString("public const string TABLE = 'custom_audit_log';", $tableContents);
		$this->assertSame(Command::SUCCESS, $migrationStatus);
		$this->assertStringContainsString('namespace Acme\\Plugin\\Storage\\Migrations;', $migrationContents);
		$this->assertStringContainsString('use Acme\\Plugin\\Storage\\Audit_Log_Table;', $migrationContents);
		$this->assertStringContainsString("public const string ID = '2026_06_26_000002_create_audit_log_table';", $migrationContents);
	}

	public function test_database_provider_generator_accepts_generation_options(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->providerCommand($root));

		$statusCode = $tester->execute([
			'name'        => 'Database_Provider',
			'--namespace' => 'Acme\\Plugin\\Storage',
			'--path'      => 'custom/providers',
		]);

		$contents = (string) file_get_contents($root . '/custom/providers/Database_Provider.php');

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('namespace Acme\\Plugin\\Storage;', $contents);
		$this->assertStringContainsString('final class Database_Provider extends Service_Provider {', $contents);
	}

	public function test_database_provider_generator_accepts_an_absolute_output_path(): void {
		$root       = $this->temporaryProject();
		$outputRoot = $this->temporaryRoot('foundation-make-database-provider-output-');
		$tester     = new CommandTester($this->providerCommand($root));

		$statusCode = $tester->execute([
			'--namespace' => 'Acme\\External\\Database',
			'--path'      => $outputRoot,
		]);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertFileExists($outputRoot . '/Provider.php');
		$this->assertStringContainsString('Created: ' . $outputRoot . '/Provider.php', $tester->getDisplay());
		$this->assertStringContainsString('namespace Acme\\External\\Database;', (string) file_get_contents($outputRoot . '/Provider.php'));
	}

	public function test_table_and_migration_generators_update_the_conventional_database_provider_when_it_exists(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		(new CommandTester($this->providerCommand($root)))->execute([]);

		$tableTester = new CommandTester($this->tableCommand($root));
		$tableStatus = $tableTester->execute([
			'name' => 'reports',
		]);

		$migrationTester = new CommandTester($this->migrationCommand($root));
		$migrationStatus = $migrationTester->execute([
			'name' => 'create-reports-table',
			'--id' => '2026_06_26_000001_create_reports_table',
		]);

		$contents = (string) file_get_contents($root . '/src/Database/Provider.php');

		$this->assertSame(Command::SUCCESS, $tableStatus);
		$this->assertStringContainsString('Updated: src/Database/Provider.php', $tableTester->getDisplay());
		$this->assertSame(Command::SUCCESS, $migrationStatus);
		$this->assertStringContainsString('Updated: src/Database/Provider.php', $migrationTester->getDisplay());
		$this->assertStringContainsString('use Acme\\Plugin\\Database\\Tables\\Reports_Table;', $contents);
		$this->assertStringContainsString('use Acme\\Plugin\\Database\\Migrations\\Create_Reports_Table;', $contents);
		$this->assertStringContainsString('$this->container->singleton(Reports_Table::class);', $contents);
		$this->assertStringContainsString('$c->get(Create_Reports_Table::class),', $contents);
		$this->assertStringContainsString("\t\t\$this->container->singleton(Reports_Table::class);\n\t\t// foundation:database-tables", $contents);
		$this->assertStringContainsString("\t\t\t\$c->get(Create_Reports_Table::class),\n\t\t] );", $contents);
		$this->assertStringNotContainsString('Array$this', $contents);
		$this->assertStringNotContainsString('Array$c', $contents);

		(new CommandTester($this->tableCommand($root)))->execute([
			'name'    => 'reports',
			'--force' => true,
		]);
		(new CommandTester($this->migrationCommand($root)))->execute([
			'name'    => 'create-reports-table',
			'--id'    => '2026_06_26_000001_create_reports_table',
			'--force' => true,
		]);

		$this->assertSame($contents, (string) file_get_contents($root . '/src/Database/Provider.php'));
	}

	public function test_database_migration_generator_appends_to_existing_provider_migrations_in_order(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		(new CommandTester($this->providerCommand($root)))->execute([]);

		(new CommandTester($this->migrationCommand($root)))->execute([
			'name' => 'create-reports-table',
			'--id' => '2026_06_26_000001_create_reports_table',
		]);
		(new CommandTester($this->migrationCommand($root)))->execute([
			'name' => 'create-orders-table',
			'--id' => '2026_06_26_000002_create_orders_table',
		]);

		$contents = (string) file_get_contents($root . '/src/Database/Provider.php');

		$reportsOffset = strpos($contents, '$c->get(Create_Reports_Table::class),');
		$ordersOffset  = strpos($contents, '$c->get(Create_Orders_Table::class),');

		$this->assertIsInt($reportsOffset);
		$this->assertIsInt($ordersOffset);
		$this->assertGreaterThan($reportsOffset, $ordersOffset);
	}

	public function test_table_and_migration_generators_update_an_explicit_database_provider_when_it_exists(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		(new CommandTester($this->providerCommand($root)))->execute([
			'--path' => 'custom/providers',
		]);

		$tableTester = new CommandTester($this->tableCommand($root));
		$tableStatus = $tableTester->execute([
			'name'       => 'reports',
			'--provider' => 'custom/providers/Provider.php',
		]);

		$migrationTester = new CommandTester($this->migrationCommand($root));
		$migrationStatus = $migrationTester->execute([
			'name'       => 'create-reports-table',
			'--id'       => '2026_06_26_000001_create_reports_table',
			'--provider' => 'custom/providers/Provider.php',
		]);

		$contents = (string) file_get_contents($root . '/custom/providers/Provider.php');

		$this->assertSame(Command::SUCCESS, $tableStatus);
		$this->assertStringContainsString('Updated: custom/providers/Provider.php', $tableTester->getDisplay());
		$this->assertSame(Command::SUCCESS, $migrationStatus);
		$this->assertStringContainsString('Updated: custom/providers/Provider.php', $migrationTester->getDisplay());
		$this->assertStringContainsString('$this->container->singleton(Reports_Table::class);', $contents);
		$this->assertStringContainsString('$c->get(Create_Reports_Table::class),', $contents);
	}

	public function test_explicit_database_provider_update_fails_when_the_provider_has_no_markers(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		mkdir($root . '/custom/providers', 0777, true);
		file_put_contents($root . '/custom/providers/Provider.php', '<?php declare(strict_types=1); namespace Acme\\Plugin\\Database; final class Provider {}');

		$tester     = new CommandTester($this->tableCommand($root));
		$statusCode = $tester->execute([
			'name'       => 'reports',
			'--provider' => 'custom/providers/Provider.php',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('file does not contain the generated database provider markers', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Tables/Reports_Table.php');
	}

	public function test_explicit_database_provider_migration_update_fails_when_the_provider_has_no_migration_anchor(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		mkdir($root . '/custom/providers', 0777, true);
		file_put_contents($root . '/custom/providers/Provider.php', <<<'PHP'
<?php declare(strict_types=1);

namespace Acme\Plugin\Database;

final class Provider
{
	public function register(): void {
		// foundation:database-tables
	}
}
PHP);

		$tester     = new CommandTester($this->migrationCommand($root));
		$statusCode = $tester->execute([
			'name'       => 'create-reports-table',
			'--id'       => '2026_06_26_000001_create_reports_table',
			'--provider' => 'custom/providers/Provider.php',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('file does not contain a generated database provider registration point', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Migrations/Create_Reports_Table.php');
	}

	public function test_database_provider_migration_update_preserves_legacy_migration_marker_position(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Database', 0777, true);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, <<<'PHP'
<?php declare(strict_types=1);

namespace Acme\Plugin\Database;

use lucatume\DI52\Container as C;
use StellarWP\Foundation\Database\DatabaseProvider;

final class Provider
{
	public function register(): void {
		$this->container->mergeArrayVar( DatabaseProvider::MIGRATIONS, static fn ( C $c ): array => [
			// foundation:database-migrations
		] );
	}
}
PHP);

		$status = $this->providerUpdater()->addMigration(
			providerPath: $providerPath,
			class: 'Create_Reports_Table',
			classNamespace: 'Acme\\Plugin\\Database\\Migrations'
		);

		$contents = (string) file_get_contents($providerPath);

		$this->assertSame(ProviderRegistrationEditor::UPDATED, $status);
		$this->assertStringContainsString('use Acme\\Plugin\\Database\\Migrations\\Create_Reports_Table;', $contents);
		$this->assertStringContainsString("\t\t\t\$c->get(Create_Reports_Table::class),\n\t\t\t// foundation:database-migrations", $contents);
	}

	public function test_database_provider_migration_update_supports_direct_array_registrations(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Database', 0777, true);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, <<<'PHP'
<?php declare(strict_types=1);

namespace Acme\Plugin\Database;

use StellarWP\Foundation\Database\DatabaseProvider;

final class Provider
{
	public function register(): void {
		$this->container->mergeArrayVar( DatabaseProvider::MIGRATIONS, [
		] );
	}
}
PHP);

		$status = $this->providerUpdater()->addMigration(
			providerPath: $providerPath,
			class: 'Create_Reports_Table',
			classNamespace: 'Acme\\Plugin\\Database\\Migrations'
		);

		$contents = (string) file_get_contents($providerPath);

		$this->assertSame(ProviderRegistrationEditor::UPDATED, $status);
		$this->assertStringContainsString('$this->container->get(Create_Reports_Table::class),', $contents);
	}

	public function test_database_provider_migration_update_supports_closure_callbacks_returning_arrays(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Database', 0777, true);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, <<<'PHP'
<?php declare(strict_types=1);

namespace Acme\Plugin\Database;

use lucatume\DI52\Container as C;
use StellarWP\Foundation\Database\DatabaseProvider;

final class Provider
{
	public function register(): void {
		$this->container->mergeArrayVar( DatabaseProvider::MIGRATIONS, static function ( C $c ): array {
			return [
			];
		} );
	}
}
PHP);

		$status = $this->providerUpdater()->addMigration(
			providerPath: $providerPath,
			class: 'Create_Reports_Table',
			classNamespace: 'Acme\\Plugin\\Database\\Migrations'
		);

		$contents = (string) file_get_contents($providerPath);

		$this->assertSame(ProviderRegistrationEditor::UPDATED, $status);
		$this->assertStringContainsString('use Acme\\Plugin\\Database\\Migrations\\Create_Reports_Table;', $contents);
		$this->assertStringContainsString("\t\t\t\t\$c->get(Create_Reports_Table::class),\n\t\t\t];", $contents);
	}

	public function test_database_provider_migration_update_uses_the_callback_parameter_name(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Database', 0777, true);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, <<<'PHP'
<?php declare(strict_types=1);

namespace Acme\Plugin\Database;

use lucatume\DI52\Container as C;
use StellarWP\Foundation\Database\DatabaseProvider;

final class Provider
{
	public function register(): void {
		$this->container->mergeArrayVar( DatabaseProvider::MIGRATIONS, static fn ( C $container ): array => [
		] );
	}
}
PHP);

		$status = $this->providerUpdater()->addMigration(
			providerPath: $providerPath,
			class: 'Create_Reports_Table',
			classNamespace: 'Acme\\Plugin\\Database\\Migrations'
		);

		$contents = (string) file_get_contents($providerPath);

		$this->assertSame(ProviderRegistrationEditor::UPDATED, $status);
		$this->assertStringContainsString('$container->get(Create_Reports_Table::class),', $contents);
	}

	public function test_explicit_database_provider_migration_update_fails_before_writing_when_the_array_cannot_be_safely_edited(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		mkdir($root . '/custom/providers', 0777, true);
		file_put_contents($root . '/custom/providers/Provider.php', <<<'PHP'
<?php declare(strict_types=1);

namespace Acme\Plugin\Database;

use lucatume\DI52\Container as C;
use StellarWP\Foundation\Database\DatabaseProvider;

final class Provider
{
	public function register(): void {
		$this->container->mergeArrayVar( DatabaseProvider::MIGRATIONS, static fn ( C $c ): array => [] );
	}
}
PHP);

		$tester     = new CommandTester($this->migrationCommand($root));
		$statusCode = $tester->execute([
			'name'       => 'create-reports-table',
			'--id'       => '2026_06_26_000001_create_reports_table',
			'--provider' => 'custom/providers/Provider.php',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('file does not contain a generated database provider registration point', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Migrations/Create_Reports_Table.php');
	}

	public function test_explicit_database_provider_migration_update_fails_when_the_callback_has_no_container_parameter(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		mkdir($root . '/custom/providers', 0777, true);
		file_put_contents($root . '/custom/providers/Provider.php', <<<'PHP'
<?php declare(strict_types=1);

namespace Acme\Plugin\Database;

use StellarWP\Foundation\Database\DatabaseProvider;

final class Provider
{
	public function register(): void {
		$this->container->mergeArrayVar( DatabaseProvider::MIGRATIONS, static fn (): array => [
		] );
	}
}
PHP);

		$tester     = new CommandTester($this->migrationCommand($root));
		$statusCode = $tester->execute([
			'name'       => 'create-reports-table',
			'--id'       => '2026_06_26_000001_create_reports_table',
			'--provider' => 'custom/providers/Provider.php',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('file does not contain a generated database provider registration point', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Migrations/Create_Reports_Table.php');
	}

	public function test_database_provider_migration_update_ignores_unrelated_database_provider_imports(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Database', 0777, true);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, <<<'PHP'
<?php declare(strict_types=1);

namespace Acme\Plugin\Database;

use Acme\Other\DatabaseProvider;
use lucatume\DI52\Container as C;

final class Provider
{
	public function register(): void {
		$this->container->mergeArrayVar( DatabaseProvider::MIGRATIONS, static fn ( C $c ): array => [
		] );
	}
}
PHP);

		$status = $this->providerUpdater()->addMigration(
			providerPath: $providerPath,
			class: 'Create_Reports_Table',
			classNamespace: 'Acme\\Plugin\\Database\\Migrations'
		);

		$this->assertSame(ProviderRegistrationEditor::MISSING_ANCHOR, $status);
		$this->assertStringNotContainsString('Create_Reports_Table', (string) file_get_contents($providerPath));
	}

	public function test_explicit_database_provider_update_fails_when_the_provider_cannot_be_parsed(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		mkdir($root . '/custom/providers', 0777, true);
		file_put_contents($root . '/custom/providers/Provider.php', '<?php declare(strict_types=1); namespace Acme\\Plugin\\Database; final class Provider {');

		$tester     = new CommandTester($this->tableCommand($root));
		$statusCode = $tester->execute([
			'name'       => 'reports',
			'--provider' => 'custom/providers/Provider.php',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('file could not be parsed as PHP', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Tables/Reports_Table.php');
	}

	public function test_database_provider_updates_ignore_marker_text_that_is_not_on_a_marker_line(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		(new CommandTester($this->providerCommand($root)))->execute([]);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, str_replace(
			'private function register_tables(): void {',
			"/**\n\t * Example text: // foundation:database-tables\n\t */\n\tprivate function register_tables(): void {",
			(string) file_get_contents($providerPath)
		));

		$tester     = new CommandTester($this->tableCommand($root));
		$statusCode = $tester->execute([
			'name' => 'reports',
		]);

		$contents = (string) file_get_contents($providerPath);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertSame(1, substr_count($contents, '$this->container->singleton(Reports_Table::class);'));
		$this->assertStringContainsString('Example text: // foundation:database-tables', $contents);
	}

	public function test_database_provider_updater_adds_import_after_namespace_when_no_imports_exist(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Database', 0777, true);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, <<<'PHP'
<?php declare(strict_types=1);

namespace Acme\Plugin\Database;

final class Provider
{
	public function register(): void {
		// foundation:database-tables
	}
}
PHP);

		$status = $this->providerUpdater()->addTable(
			providerPath: $providerPath,
			class: 'Reports_Table',
			classNamespace: 'Acme\\Plugin\\Database\\Tables'
		);

		$contents = (string) file_get_contents($providerPath);

		$this->assertSame(ProviderRegistrationEditor::UPDATED, $status);
		$this->assertStringContainsString("namespace Acme\\Plugin\\Database;\n\nuse Acme\\Plugin\\Database\\Tables\\Reports_Table;\n\nfinal class Provider", $contents);
		$this->assertStringContainsString("\t\t\$this->container->singleton(Reports_Table::class);\n\t\t// foundation:database-tables", $contents);
		$this->assertStringNotContainsString('Array$this', $contents);
	}

	public function test_database_provider_updater_adds_import_when_same_class_uses_a_different_alias(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Database', 0777, true);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, <<<'PHP'
<?php declare(strict_types=1);

namespace Acme\Plugin\Database;

use Acme\Plugin\Database\Tables\Reports_Table as Existing_Reports_Table;

final class Provider
{
	public function register(): void {
		// foundation:database-tables
	}
}
PHP);

		$status = $this->providerUpdater()->addTable(
			providerPath: $providerPath,
			class: 'Reports_Table',
			classNamespace: 'Acme\\Plugin\\Database\\Tables'
		);

		$contents = (string) file_get_contents($providerPath);

		$this->assertSame(ProviderRegistrationEditor::UPDATED, $status);
		$this->assertStringContainsString('use Acme\\Plugin\\Database\\Tables\\Reports_Table as Existing_Reports_Table;', $contents);
		$this->assertStringContainsString('use Acme\\Plugin\\Database\\Tables\\Reports_Table;', $contents);
		$this->assertStringContainsString("\t\t\$this->container->singleton(Reports_Table::class);\n\t\t// foundation:database-tables", $contents);
	}

	public function test_database_provider_updater_preserves_inline_comments_when_adding_imports(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Database', 0777, true);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, <<<'PHP'
<?php declare(strict_types=1);

namespace Acme\Plugin\Database;

use Acme\Plugin\Database\Existing_Table; // keep this comment here

final class Provider
{
	public function register(): void {
		// foundation:database-tables
	}
}
PHP);

		$status = $this->providerUpdater()->addTable(
			providerPath: $providerPath,
			class: 'Reports_Table',
			classNamespace: 'Acme\\Plugin\\Database\\Tables'
		);

		$contents = (string) file_get_contents($providerPath);

		$this->assertSame(ProviderRegistrationEditor::UPDATED, $status);
		$this->assertStringContainsString("use Acme\\Plugin\\Database\\Existing_Table; // keep this comment here\nuse Acme\\Plugin\\Database\\Tables\\Reports_Table;", $contents);
	}

	public function test_database_provider_updater_ignores_marker_text_inside_non_marker_line_comments(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Database', 0777, true);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, <<<'PHP'
<?php declare(strict_types=1);

namespace Acme\Plugin\Database;

final class Provider
{
	public function register(): void {
		$ignored = true; // foundation:database-tables
	}
}
PHP);

		$status = $this->providerUpdater()->addTable(
			providerPath: $providerPath,
			class: 'Reports_Table',
			classNamespace: 'Acme\\Plugin\\Database\\Tables'
		);

		$this->assertSame(ProviderRegistrationEditor::MISSING_MARKER, $status);
		$this->assertSame(0, substr_count((string) file_get_contents($providerPath), '$this->container->singleton(Reports_Table::class);'));
	}

	public function test_database_provider_updater_is_idempotent_with_grouped_imports(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Database', 0777, true);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, <<<'PHP'
<?php declare(strict_types=1);

namespace Acme\Plugin\Database;

use Acme\Plugin\Database\Tables\{Reports_Table};

final class Provider
{
	public function register(): void {
		$this->container->singleton(Reports_Table::class);
		// foundation:database-tables
	}
}
PHP);

		$contents = (string) file_get_contents($providerPath);
		$status   = $this->providerUpdater()->addTable(
			providerPath: $providerPath,
			class: 'Reports_Table',
			classNamespace: 'Acme\\Plugin\\Database\\Tables'
		);

		$this->assertSame(ProviderRegistrationEditor::ALREADY_REGISTERED, $status);
		$this->assertSame($contents, (string) file_get_contents($providerPath));
	}

	public function test_explicit_database_provider_update_fails_on_import_short_name_collisions(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		(new CommandTester($this->providerCommand($root)))->execute([]);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, str_replace(
			'use StellarWP\\Foundation\\Database\\DatabaseProvider;',
			"use Acme\\Other\\Reports_Table;\nuse StellarWP\\Foundation\\Database\\DatabaseProvider;",
			(string) file_get_contents($providerPath)
		));

		$tester     = new CommandTester($this->tableCommand($root));
		$statusCode = $tester->execute([
			'name'       => 'reports',
			'--provider' => 'src/Database/Provider.php',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('a different imported class uses the same short class name', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Tables/Reports_Table.php');
	}

	public function test_explicit_database_provider_update_fails_on_grouped_import_short_name_collisions(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		(new CommandTester($this->providerCommand($root)))->execute([]);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, str_replace(
			'use StellarWP\\Foundation\\Database\\DatabaseProvider;',
			"use Acme\\Other\\{Reports as Reports_Table};\nuse StellarWP\\Foundation\\Database\\DatabaseProvider;",
			(string) file_get_contents($providerPath)
		));

		$tester     = new CommandTester($this->tableCommand($root));
		$statusCode = $tester->execute([
			'name'       => 'reports',
			'--provider' => 'src/Database/Provider.php',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('a different imported class uses the same short class name', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Tables/Reports_Table.php');
	}

	public function test_explicit_database_provider_update_fails_on_aliased_import_short_name_collisions(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		(new CommandTester($this->providerCommand($root)))->execute([]);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, str_replace(
			'use StellarWP\\Foundation\\Database\\DatabaseProvider;',
			"use Acme\\Other\\Reports as Reports_Table;\nuse StellarWP\\Foundation\\Database\\DatabaseProvider;",
			(string) file_get_contents($providerPath)
		));

		$tester     = new CommandTester($this->tableCommand($root));
		$statusCode = $tester->execute([
			'name'       => 'reports',
			'--provider' => 'src/Database/Provider.php',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('a different imported class uses the same short class name', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Tables/Reports_Table.php');
	}

	public function test_database_table_generator_accepts_an_absolute_output_path(): void {
		$root       = $this->temporaryProject();
		$outputRoot = $this->temporaryRoot('foundation-make-database-output-');
		$tester     = new CommandTester($this->tableCommand($root));

		$statusCode = $tester->execute([
			'name'   => 'reports',
			'--path' => $outputRoot,
		]);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertFileExists($outputRoot . '/Reports_Table.php');
		$this->assertStringContainsString('Created: ' . $outputRoot . '/Reports_Table.php', $tester->getDisplay());
	}

	public function test_database_generators_use_strauss_namespace_prefix_for_foundation_imports(): void {
		$root = $this->temporaryProject([
			'extra' => [
				'strauss' => [
					'namespace_prefix' => 'Acme\\Product\\',
				],
			],
		]);

		$tableTester = new CommandTester($this->tableCommand($root));
		$tableTester->execute([
			'name' => 'reports',
		]);

		$migrationTester = new CommandTester($this->migrationCommand($root));
		$migrationTester->execute([
			'name' => 'create-reports-table',
			'--id' => '2026_06_26_000001_create_reports_table',
		]);

		$tableContents     = (string) file_get_contents($root . '/src/Database/Tables/Reports_Table.php');
		$migrationContents = (string) file_get_contents($root . '/src/Database/Migrations/Create_Reports_Table.php');

		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Database\\Contracts\\Database;', $tableContents);
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Database\\Contracts\\Migration;', $migrationContents);
		$this->assertStringNotContainsString('use StellarWP\\Foundation\\Database\\Contracts\\Database;', $tableContents);
		$this->assertStringNotContainsString('use StellarWP\\Foundation\\Database\\Contracts\\Migration;', $migrationContents);
	}

	public function test_database_provider_generator_uses_strauss_namespace_prefix_for_foundation_imports(): void {
		$root = $this->temporaryProject([
			'extra' => [
				'strauss' => [
					'namespace_prefix' => 'Acme\\Product\\',
				],
			],
		]);

		$statusCode = (new CommandTester($this->providerCommand($root)))->execute([]);

		$contents = (string) file_get_contents($root . '/src/Database/Provider.php');

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Database\\DatabaseProvider;', $contents);
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Container\\Contracts\\Provider as Service_Provider;', $contents);
		$this->assertStringNotContainsString('use StellarWP\\Foundation\\Database\\DatabaseProvider;', $contents);
	}

	public function test_database_generators_use_project_stub_overrides(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/foundation/stubs/database', 0777, true);
		file_put_contents($root . '/foundation/stubs/database/table.stub', 'Generated table {{ class }} in {{ namespace }}');
		file_put_contents($root . '/foundation/stubs/database/table-migration.stub', 'Generated migration {{ class }} with {{ table_class }}');
		file_put_contents($root . '/foundation/stubs/database/migration.stub', 'Generated migration {{ class }}');
		file_put_contents($root . '/foundation/stubs/database/provider.stub', 'Generated provider {{ class }} in {{ namespace }}');

		(new CommandTester($this->tableCommand($root)))->execute([
			'name' => 'reports',
		]);
		(new CommandTester($this->migrationCommand($root)))->execute([
			'name' => 'create-reports-table',
			'--id' => '2026_06_26_000001_create_reports_table',
		]);
		(new CommandTester($this->migrationCommand($root)))->execute([
			'name' => 'bump-version',
			'--id' => '2026_06_26_000003_bump_version',
		]);
		(new CommandTester($this->providerCommand($root)))->execute([]);

		$this->assertSame(
			'Generated table Reports_Table in Acme\\Plugin\\Database\\Tables',
			(string) file_get_contents($root . '/src/Database/Tables/Reports_Table.php')
		);
		$this->assertSame(
			'Generated migration Create_Reports_Table with Reports_Table',
			(string) file_get_contents($root . '/src/Database/Migrations/Create_Reports_Table.php')
		);
		$this->assertSame(
			'Generated migration Bump_Version',
			(string) file_get_contents($root . '/src/Database/Migrations/Bump_Version.php')
		);
		$this->assertSame(
			'Generated provider Provider in Acme\\Plugin\\Database',
			(string) file_get_contents($root . '/src/Database/Provider.php')
		);
	}

	public function test_database_generators_warn_when_the_runtime_dependency_is_missing_from_production_requirements(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->tableCommand($root));

		$statusCode = $tester->execute([
			'name' => 'reports',
		]);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('Runtime dependency missing:', $tester->getDisplay());
		$this->assertStringContainsString('composer require stellarwp/foundation-database', $tester->getDisplay());
	}

	public function test_database_provider_generator_warns_when_the_runtime_dependency_is_missing_from_production_requirements(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->providerCommand($root));

		$statusCode = $tester->execute([]);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('Runtime dependency missing:', $tester->getDisplay());
		$this->assertStringContainsString('composer require stellarwp/foundation-database', $tester->getDisplay());
	}

	public function test_database_provider_generator_warns_when_the_runtime_dependency_is_only_a_development_dependency(): void {
		$root = $this->temporaryProject([
			'require-dev' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);
		$tester = new CommandTester($this->providerCommand($root));

		$statusCode = $tester->execute([]);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('Runtime dependency missing:', $tester->getDisplay());
		$this->assertStringContainsString('only in require-dev', $tester->getDisplay());
	}

	public function test_database_provider_generator_does_not_warn_when_the_runtime_dependency_is_in_production_requirements(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);
		$tester = new CommandTester($this->providerCommand($root));

		$statusCode = $tester->execute([]);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringNotContainsString('Runtime dependency missing:', $tester->getDisplay());
	}

	public function test_database_provider_generator_does_not_warn_when_the_aggregate_runtime_dependency_is_in_production_requirements(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation' => '^1.2',
			],
		]);
		$tester = new CommandTester($this->providerCommand($root));

		$statusCode = $tester->execute([]);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringNotContainsString('Runtime dependency missing:', $tester->getDisplay());
	}

	public function test_database_generators_warn_when_the_runtime_dependency_is_only_a_development_dependency(): void {
		$root = $this->temporaryProject([
			'require-dev' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute([
			'name' => 'create-reports-table',
			'--id' => '2026_06_26_000001_create_reports_table',
		]);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('Runtime dependency missing:', $tester->getDisplay());
		$this->assertStringContainsString('only in require-dev', $tester->getDisplay());
	}

	public function test_database_generators_do_not_warn_when_the_runtime_dependency_is_in_production_requirements(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute([
			'name' => 'create-reports-table',
			'--id' => '2026_06_26_000001_create_reports_table',
		]);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringNotContainsString('Runtime dependency missing:', $tester->getDisplay());
	}

	public function test_database_generators_reject_invalid_namespaces_before_writing_files(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->tableCommand($root));

		$statusCode = $tester->execute([
			'name'        => 'reports',
			'--namespace' => 'Acme Plugin\\Database\\Tables',
			'--path'      => 'custom/tables',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('Namespace "Acme Plugin\\Database\\Tables" is not a valid PHP namespace.', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/custom/tables/Reports_Table.php');
	}

	public function test_database_provider_generator_rejects_invalid_namespaces_before_writing_files(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->providerCommand($root));

		$statusCode = $tester->execute([
			'--namespace' => 'Acme Plugin\\Database',
			'--path'      => 'custom/providers',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('Namespace "Acme Plugin\\Database" is not a valid PHP namespace.', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/custom/providers/Provider.php');
	}

	public function test_database_generators_reject_namespaces_outside_the_autoload_root(): void {
		$root        = $this->temporaryProject();
		$tableTester = new CommandTester($this->tableCommand($root));

		$tableStatus = $tableTester->execute([
			'name'        => 'reports',
			'--namespace' => 'Acme\\PluginTools\\Database\\Tables',
		]);

		$migrationTester = new CommandTester($this->migrationCommand($root));
		$migrationStatus = $migrationTester->execute([
			'name'        => 'create-reports-table',
			'--namespace' => 'Acme\\PluginTools\\Database\\Migrations',
		]);

		$this->assertSame(Command::FAILURE, $tableStatus);
		$this->assertStringContainsString('Namespace "Acme\\PluginTools\\Database\\Tables" is outside the Composer PSR-4 namespaces in composer.json.', $tableTester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Tools/Database/Tables/Reports_Table.php');
		$this->assertSame(Command::FAILURE, $migrationStatus);
		$this->assertStringContainsString('Namespace "Acme\\PluginTools\\Database\\Migrations" is outside the Composer PSR-4 namespaces in composer.json.', $migrationTester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Tools/Database/Migrations/Create_Reports_Table.php');
	}

	public function test_database_provider_generator_rejects_namespaces_outside_the_autoload_root(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->providerCommand($root));

		$statusCode = $tester->execute([
			'--namespace' => 'Acme\\PluginTools\\Database',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('Namespace "Acme\\PluginTools\\Database" is outside the Composer PSR-4 namespaces in composer.json.', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Tools/Database/Provider.php');
	}

	private function tableCommand(string $root): TableCommand {
		return new TableCommand(
			rootPath: $root,
			autoloadResolver: new ComposerAutoloadResolver($root),
			classNameResolver: new WordPressClassNameResolver(),
			stubResolver: new StubResolver($root),
			stubRenderer: new StubRenderer(),
			fileWriter: new GeneratedFileWriter(),
			providerUpdater: $this->providerUpdater()
		);
	}

	private function migrationCommand(string $root): MigrationCommand {
		return new MigrationCommand(
			rootPath: $root,
			autoloadResolver: new ComposerAutoloadResolver($root),
			classNameResolver: new WordPressClassNameResolver(),
			stubResolver: new StubResolver($root),
			stubRenderer: new StubRenderer(),
			fileWriter: new GeneratedFileWriter(),
			providerUpdater: $this->providerUpdater()
		);
	}

	private function providerCommand(string $root): ProviderCommand {
		return new ProviderCommand(
			rootPath: $root,
			autoloadResolver: new ComposerAutoloadResolver($root),
			classNameResolver: new WordPressClassNameResolver(),
			stubResolver: new StubResolver($root),
			stubRenderer: new StubRenderer(),
			fileWriter: new GeneratedFileWriter()
		);
	}

	private function providerUpdater(): ProviderRegistrationEditor {
		return new ProviderRegistrationEditor(
			sourceEditor: new PhpSourceEditor(
				parserFactory: new ParserFactory(),
				lexer: new Lexer()
			)
		);
	}

	/**
	 * @param array<string,mixed> $composer
	 */
	private function temporaryProject(array $composer = []): string {
		$root = $this->temporaryRoot('foundation-make-database-test-');

		file_put_contents($root . '/composer.json', json_encode(array_replace_recursive([
			'autoload' => [
				'psr-4' => [
					'Acme\\Plugin\\' => 'src',
				],
			],
		], $composer), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

		return $root;
	}

	private function temporaryRoot(string $prefix): string {
		$root = $this->tempDir . '/' . $prefix . bin2hex(random_bytes(8));

		if (! mkdir($root, 0777, true) && ! is_dir($root)) {
			$this->fail(sprintf('Could not create temporary root "%s".', $root));
		}

		$this->temporaryRoots[] = $root;

		return $root;
	}

	private function removeDirectory(string $directory): void {
		if (! is_dir($directory)) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($files as $file) {
			if ($file->isDir()) {
				rmdir($file->getPathname());
			} else {
				unlink($file->getPathname());
			}
		}

		rmdir($directory);
	}
}
