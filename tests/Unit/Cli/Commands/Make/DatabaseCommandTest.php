<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Commands\Make;

use phpmock\mockery\PHPMockery;
use PhpParser\Lexer;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use StellarWP\Foundation\Cli\Commands\Make\Database\Factories\MigrationFileFactory;
use StellarWP\Foundation\Cli\Commands\Make\Database\MigrationCommand;
use StellarWP\Foundation\Cli\Commands\Make\Database\ProviderCommand;
use StellarWP\Foundation\Cli\Commands\Make\Database\ProviderRegistrationEditor;
use StellarWP\Foundation\Cli\Commands\Make\Database\TableCommand;
use StellarWP\Foundation\Cli\Generation\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
use StellarWP\Foundation\Cli\Generation\Php\PhpSourceEditor;
use StellarWP\Foundation\Cli\Generation\StubRenderer;
use StellarWP\Foundation\Cli\Generation\StubResolver;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
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
		$this->assertStringContainsString('Create a migration that defines this table with Blueprint and Schema::create().', $tester->getDisplay());

		$contents = (string) file_get_contents($path);

		$this->assertStringContainsString('namespace Acme\\Plugin\\Database\\Tables;', $contents);
		$this->assertStringContainsString('use StellarWP\\Foundation\\Database\\Table\\Table;', $contents);
		$this->assertStringContainsString('final readonly class Reports_Table extends Table {', $contents);
		$this->assertStringContainsString("private const string UNPREFIXED_TABLE_NAME = 'reports';", $contents);
		$this->assertStringContainsString('return self::UNPREFIXED_TABLE_NAME;', $contents);
		$this->assertStringNotContainsString('function __construct', $contents);
		$this->assertFalse($this->tableCommand($root)->getDefinition()->hasOption('id'));
		$this->assertStringNotContainsString('Blueprint', $contents);
	}

	public function test_database_table_generator_can_create_and_register_its_initial_migration(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^2.0',
			],
		]);

		(new CommandTester($this->providerCommand($root)))->execute([]);

		$tester = new CommandTester($this->tableCommand($root));
		$status = $tester->execute([
			'name'        => 'reports',
			'--migration' => true,
		]);

		$tablePath     = $root . '/src/Database/Tables/Reports_Table.php';
		$migrationPath = $root . '/src/Database/Migrations/Create_Reports_Table.php';
		$providerPath  = $root . '/src/Database/Provider.php';

		$this->assertSame(Command::SUCCESS, $status);
		$this->assertFileExists($tablePath);
		$this->assertFileExists($migrationPath);
		$this->assertStringContainsString('Created: src/Database/Tables/Reports_Table.php', $tester->getDisplay());
		$this->assertStringContainsString('Created: src/Database/Migrations/Create_Reports_Table.php', $tester->getDisplay());
		$this->assertStringContainsString('Updated: src/Database/Provider.php', $tester->getDisplay());

		$migration = (string) file_get_contents($migrationPath);
		$provider  = (string) file_get_contents($providerPath);

		$this->assertStringContainsString('use Acme\\Plugin\\Database\\Tables\\Reports_Table;', $migration);
		$this->assertStringContainsString('$blueprint = Blueprint::for( $this->table );', $migration);
		$this->assertStringContainsString("\$blueprint->bigIncrements( 'id' );", $migration);
		$this->assertStringContainsString('Define the complete initial schema before running this migration.', $migration);
		$this->assertStringContainsString('$schema->create( $blueprint );', $migration);
		$this->assertStringContainsString('$schema->drop( $this->table );', $migration);
		$this->assertStringContainsString('$this->container->singleton( Reports_Table::class );', $provider);
		$this->assertStringContainsString('$c->get( Create_Reports_Table::class ),', $provider);
	}

	public function test_database_table_generator_uses_its_custom_namespace_for_the_initial_migration(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->tableCommand($root));

		$status = $tester->execute([
			'name'        => 'audit-log',
			'--namespace' => 'Acme\\Plugin\\Storage',
			'--path'      => 'custom/tables',
			'--migration' => true,
		]);

		$migration = (string) file_get_contents($root . '/src/Database/Migrations/Create_Audit_Log_Table.php');

		$this->assertSame(Command::SUCCESS, $status);
		$this->assertFileExists($root . '/custom/tables/Audit_Log_Table.php');
		$this->assertStringContainsString('use Acme\\Plugin\\Storage\\Audit_Log_Table;', $migration);
		$this->assertStringContainsString(
			'Register Audit_Log_Table and Create_Audit_Log_Table with your database provider.',
			$tester->getDisplay()
		);
	}

	public function test_database_table_generator_accepts_an_explicit_initial_migration_id(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->tableCommand($root));

		$status = $tester->execute([
			'name'           => 'reports',
			'--migration'    => true,
			'--migration-id' => '2026_09_01_120000_create_reports_table',
		]);

		$migration = (string) file_get_contents($root . '/src/Database/Migrations/Create_Reports_Table.php');

		$this->assertSame(Command::SUCCESS, $status);
		$this->assertStringContainsString(
			"public const string ID = '2026_09_01_120000_create_reports_table';",
			$migration
		);
	}

	public function test_database_table_generator_rejects_a_migration_id_without_a_migration(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->tableCommand($root));

		$status = $tester->execute([
			'name'           => 'reports',
			'--migration-id' => '2026_09_01_120000_create_reports_table',
		]);

		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringContainsString('The --migration-id option requires --migration.', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Tables/Reports_Table.php');
	}

	public function test_database_table_generator_rejects_an_explicit_blank_table_name(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->tableCommand($root));

		$status = $tester->execute([
			'name'         => 'reports',
			'--table-name' => '',
		]);

		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringContainsString('The --table-name option cannot be blank or contain surrounding whitespace.', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Tables/Reports_Table.php');
	}

	public function test_database_table_generator_rejects_invalid_unprefixed_table_names(): void {
		$invalidNames = [
			[' reports', 'cannot be blank or contain surrounding whitespace'],
			['report-items', 'may contain only ASCII letters, numbers, and underscores'],
		];

		foreach ($invalidNames as [$tableName, $message]) {
			$root   = $this->temporaryProject();
			$tester = new CommandTester($this->tableCommand($root));

			$status = $tester->execute([
				'name'         => 'reports',
				'--table-name' => $tableName,
			]);

			$this->assertSame(Command::FAILURE, $status);
			$this->assertStringContainsString($message, $tester->getDisplay());
			$this->assertFileDoesNotExist($root . '/src/Database/Tables/Reports_Table.php');
		}
	}

	public function test_database_table_generator_does_not_write_either_file_when_the_initial_migration_exists(): void {
		$root          = $this->temporaryProject();
		$migrationPath = $root . '/src/Database/Migrations/Create_Reports_Table.php';

		mkdir(dirname($migrationPath), 0777, true);
		file_put_contents($migrationPath, 'existing migration');

		$tester = new CommandTester($this->tableCommand($root));
		$status = $tester->execute([
			'name'        => 'reports',
			'--migration' => true,
		]);

		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringContainsString(
			'Migration already exists: src/Database/Migrations/Create_Reports_Table.php. Edit it directly or create a new migration.',
			$tester->getDisplay()
		);
		$this->assertFileDoesNotExist($root . '/src/Database/Tables/Reports_Table.php');
		$this->assertSame('existing migration', (string) file_get_contents($migrationPath));
	}

	public function test_it_generates_a_database_migration_from_project_autoload_defaults(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute([
			'name'     => 'create-reports-table',
			'--create' => 'Reports_Table',
			'--id'     => '2026_06_26_000001_create_reports_table',
		]);

		$path = $root . '/src/Database/Migrations/Create_Reports_Table.php';

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertFileExists($path);
		$this->assertStringContainsString('Created: src/Database/Migrations/Create_Reports_Table.php', $tester->getDisplay());

		$contents = (string) file_get_contents($path);

		$this->assertStringContainsString('namespace Acme\\Plugin\\Database\\Migrations;', $contents);
		$this->assertStringContainsString('use StellarWP\\Foundation\\Database\\Contracts\\Migration;', $contents);
		$this->assertStringContainsString('use StellarWP\\Foundation\\Database\\Contracts\\Schema;', $contents);
		$this->assertStringContainsString('use Acme\\Plugin\\Database\\Tables\\Reports_Table;', $contents);
		$this->assertStringContainsString('final readonly class Create_Reports_Table implements Migration {', $contents);
		$this->assertStringContainsString("public const string ID = '2026_06_26_000001_create_reports_table';", $contents);
		$this->assertStringContainsString('private Reports_Table $table', $contents);
		$this->assertStringContainsString('use StellarWP\\Foundation\\Database\\Table\\Blueprint;', $contents);
		$this->assertStringContainsString('$blueprint = Blueprint::for( $this->table );', $contents);
		$this->assertStringContainsString('$schema->create( $blueprint );', $contents);
		$this->assertStringContainsString('$schema->drop( $this->table );', $contents);
		$this->assertStringNotContainsString('CreateTable', $contents);
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
		$this->assertStringContainsString('use StellarWP\\Foundation\\Database\\Migration\\Exceptions\\IrreversibleMigration;', $contents);
		$this->assertStringContainsString("public const string ID = '2026_06_26_000003_bump_version';", $contents);
		$this->assertStringContainsString('throw IrreversibleMigration::forMigration( self::ID );', $contents);
		$this->assertStringNotContainsString('CreateTable', $contents);
		$this->assertStringNotContainsString('Bump_Version_Table', $contents);
	}

	public function test_it_generates_a_table_alteration_migration_without_destructive_rollback(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute([
			'name'    => 'add-status-to-reports',
			'--id'    => '2026_06_26_000004_add_status_to_reports',
			'--table' => 'Reports_Table',
		]);

		$contents = (string) file_get_contents($root . '/src/Database/Migrations/Add_Status_To_Reports.php');

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('use Acme\\Plugin\\Database\\Tables\\Reports_Table;', $contents);
		$this->assertStringContainsString('use StellarWP\\Foundation\\Database\\Migration\\Exceptions\\IrreversibleMigration;', $contents);
		$this->assertStringContainsString('private Reports_Table $table', $contents);
		$this->assertStringContainsString('$blueprint = Blueprint::for( $this->table );', $contents);
		$this->assertStringContainsString('$schema->alter( $blueprint );', $contents);
		$this->assertStringContainsString('throw IrreversibleMigration::forMigration( self::ID );', $contents);
		$this->assertStringNotContainsString('$schema->drop( $this->table );', $contents);
	}

	public function test_create_named_non_table_migrations_do_not_receive_destructive_table_behavior(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute([
			'name' => 'create-report-index',
			'--id' => '2026_06_26_000005_create_report_index',
		]);

		$contents = (string) file_get_contents($root . '/src/Database/Migrations/Create_Report_Index.php');

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('throw IrreversibleMigration::forMigration( self::ID );', $contents);
		$this->assertStringNotContainsString('$schema->drop(', $contents);
		$this->assertStringNotContainsString('Report_Index_Table', $contents);
	}

	public function test_migration_names_do_not_infer_destructive_table_behavior(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute([
			'name' => 'create-reports-table',
		]);

		$contents = (string) file_get_contents($root . '/src/Database/Migrations/Create_Reports_Table.php');

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('throw IrreversibleMigration::forMigration( self::ID );', $contents);
		$this->assertStringNotContainsString('$schema->drop(', $contents);
		$this->assertStringNotContainsString('Reports_Table $table', $contents);
	}

	public function test_database_migrations_reject_blank_table_options(): void {
		$root = $this->temporaryProject();

		$createTester = new CommandTester($this->migrationCommand($root));
		$createStatus = $createTester->execute([
			'name'     => 'create-reports-table',
			'--create' => '',
		]);

		$tableTester = new CommandTester($this->migrationCommand($root));
		$tableStatus = $tableTester->execute([
			'name'    => 'add-status-to-reports',
			'--table' => '',
		]);

		$this->assertSame(Command::FAILURE, $createStatus);
		$this->assertStringContainsString('The --create option cannot be blank.', $createTester->getDisplay());
		$this->assertSame(Command::FAILURE, $tableStatus);
		$this->assertStringContainsString('The --table option cannot be blank.', $tableTester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Migrations/Add_Status_To_Reports.php');
		$this->assertFileDoesNotExist($root . '/src/Database/Migrations/Create_Reports_Table.php');
	}

	public function test_database_migrations_reject_create_and_table_together(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute([
			'name'     => 'create-reports-table',
			'--create' => 'Reports_Table',
			'--table'  => 'Reports_Table',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('The --create and --table options cannot be used together.', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Migrations/Create_Reports_Table.php');
	}

	public function test_database_migrations_accept_fully_qualified_table_classes(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute([
			'name'     => 'create-reports-table',
			'--create' => 'Acme\\Plugin\\Storage\\ReportTable',
		]);

		$contents = (string) file_get_contents($root . '/src/Database/Migrations/Create_Reports_Table.php');

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('use Acme\\Plugin\\Storage\\ReportTable;', $contents);
		$this->assertStringContainsString('private ReportTable $table', $contents);
		$this->assertStringNotContainsString('Report_Table', $contents);
		$this->assertStringContainsString('$schema->drop( $this->table );', $contents);
	}

	public function test_database_migrations_preserve_short_table_class_names(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute([
			'name'    => 'update-report-store',
			'--table' => 'ReportTable',
		]);

		$contents = (string) file_get_contents($root . '/src/Database/Migrations/Update_Report_Store.php');

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('use Acme\\Plugin\\Database\\Tables\\ReportTable;', $contents);
		$this->assertStringContainsString('private ReportTable $table', $contents);
		$this->assertStringNotContainsString('Report_Table', $contents);
	}

	public function test_database_migrations_reject_invalid_fully_qualified_table_classes_before_writing_files(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute([
			'name'    => 'add-status-to-reports',
			'--table' => 'Acme Plugin\\Database\\Tables\\Reports_Table',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString(
			'Namespace "Acme Plugin\\Database\\Tables" is not a valid PHP namespace.',
			$tester->getDisplay()
		);
		$this->assertFileDoesNotExist($root . '/src/Database/Migrations/Add_Status_To_Reports.php');
	}

	public function test_database_migrations_reject_empty_table_namespace_segments_before_writing_files(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute([
			'name'    => 'add-status-to-reports',
			'--table' => 'Acme\\\\Reports_Table',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('is not a valid PHP namespace', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Migrations/Add_Status_To_Reports.php');
	}

	public function test_database_migrations_reject_invalid_table_class_paths(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute([
			'name'    => 'add-status-to-reports',
			'--table' => 'Acme/Plugin/Database/Tables/Reports_Table',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString(
			'Table class "Acme/Plugin/Database/Tables/Reports_Table" is not a valid PHP class name.',
			$tester->getDisplay()
		);
		$this->assertFileDoesNotExist($root . '/src/Database/Migrations/Add_Status_To_Reports.php');
	}

	public function test_database_migration_command_exposes_explicit_generation_modes(): void {
		$command = $this->migrationCommand($this->temporaryProject());

		$this->assertSame('Create a new Foundation database migration.', $command->getDescription());
		$this->assertTrue($command->getDefinition()->hasOption('create'));
		$this->assertTrue($command->getDefinition()->hasOption('table'));
		$this->assertFalse($command->getDefinition()->hasOption('table-class'));
		$this->assertFalse($command->getDefinition()->hasOption('table-namespace'));
		$this->assertStringContainsString('mutually exclusive', $command->getHelp());
		$this->assertStringContainsString('fully qualified class names', $command->getHelp());

		foreach ([
			'namespace' => 'Plugin\Database\Migrations',
			'path'      => 'src/Database/Migrations',
			'provider'  => 'src/Database/Provider.php',
			'id'        => '2026_09_04_143200_create_reports_table',
			'create'    => 'Reports_Table',
			'table'     => 'Reports_Table',
		] as $option => $example) {
			$this->assertStringContainsString(
				$example,
				$command->getDefinition()->getOption($option)->getDescription()
			);
		}
	}

	public function test_database_table_command_distinguishes_the_physical_table_name_from_a_migration_table_class(): void {
		$command = $this->tableCommand($this->temporaryProject());

		$this->assertTrue($command->getDefinition()->hasOption('table-name'));
		$this->assertFalse($command->getDefinition()->hasOption('table'));

		foreach ([
			'namespace'    => 'Plugin\Database\Tables',
			'path'         => 'src/Database/Tables',
			'provider'     => 'src/Database/Provider.php',
			'table-name'   => 'report_entries',
			'migration-id' => '2026_09_04_143200_create_reports_table',
		] as $option => $example) {
			$this->assertStringContainsString(
				$example,
				$command->getDefinition()->getOption($option)->getDescription()
			);
		}
	}

	public function test_database_provider_command_describes_customization_options_with_examples(): void {
		$command = $this->providerCommand($this->temporaryProject());

		$this->assertStringContainsString(
			'Plugin\Database',
			$command->getDefinition()->getOption('namespace')->getDescription()
		);
		$this->assertStringContainsString(
			'src/Database',
			$command->getDefinition()->getOption('path')->getDescription()
		);
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
		$this->assertStringContainsString('use StellarWP\\Foundation\\Container\\Contracts\\Resolver as C;', $contents);
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

	public function test_database_migrations_cannot_overwrite_existing_files(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^2.0',
			],
		]);
		$command = $this->migrationCommand($root);
		$path    = $root . '/src/Database/Migrations/Create_Reports_Table.php';

		(new CommandTester($this->providerCommand($root)))->execute([]);
		mkdir(dirname($path), 0777, true);
		file_put_contents($path, 'existing migration');

		$providerPath     = $root . '/src/Database/Provider.php';
		$providerContents = (string) file_get_contents($providerPath);
		$tester           = new CommandTester($command);
		$status           = $tester->execute([
			'name'     => 'create-reports-table',
			'--create' => 'Reports_Table',
			'--id'     => '2026_06_27_000001_create_reports_table',
		]);

		$this->assertFalse($command->getDefinition()->hasOption('force'));
		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringContainsString(
			'Migration already exists: src/Database/Migrations/Create_Reports_Table.php. Edit it directly or create a new migration.',
			$tester->getDisplay()
		);
		$this->assertSame('existing migration', (string) file_get_contents($path));
		$this->assertSame($providerContents, (string) file_get_contents($providerPath));
	}

	public function test_database_tables_cannot_overwrite_existing_files(): void {
		$root    = $this->temporaryProject();
		$command = $this->tableCommand($root);
		$path    = $root . '/src/Database/Tables/Reports_Table.php';

		mkdir(dirname($path), 0777, true);
		file_put_contents($path, 'existing table');

		$tester = new CommandTester($command);
		$status = $tester->execute(['name' => 'reports']);

		$this->assertFalse($command->getDefinition()->hasOption('force'));
		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringContainsString('File already exists: src/Database/Tables/Reports_Table.php.', $tester->getDisplay());
		$this->assertSame('existing table', (string) file_get_contents($path));
	}

	public function test_database_generators_accept_generation_options(): void {
		$root = $this->temporaryProject();

		$tableTester = new CommandTester($this->tableCommand($root));
		$tableStatus = $tableTester->execute([
			'name'         => 'Audit_Log',
			'--namespace'  => 'Acme\\Plugin\\Storage',
			'--path'       => 'custom/tables',
			'--table-name' => 'custom_audit_log',
		]);

		$migrationTester = new CommandTester($this->migrationCommand($root));
		$migrationStatus = $migrationTester->execute([
			'name'        => 'Create_Audit_Log_Table',
			'--namespace' => 'Acme\\Plugin\\Storage\\Migrations',
			'--path'      => 'custom/migrations',
			'--id'        => '2026_06_26_000002_create_audit_log_table',
			'--create'    => 'Acme\\Plugin\\Storage\\Audit_Log_Table',
		]);

		$tableContents     = (string) file_get_contents($root . '/custom/tables/Audit_Log_Table.php');
		$migrationContents = (string) file_get_contents($root . '/custom/migrations/Create_Audit_Log_Table.php');

		$this->assertSame(Command::SUCCESS, $tableStatus);
		$this->assertStringContainsString('namespace Acme\\Plugin\\Storage;', $tableContents);
		$this->assertStringContainsString("private const string UNPREFIXED_TABLE_NAME = 'custom_audit_log';", $tableContents);
		$this->assertSame(Command::SUCCESS, $migrationStatus);
		$this->assertStringContainsString('namespace Acme\\Plugin\\Storage\\Migrations;', $migrationContents);
		$this->assertStringContainsString('use Acme\\Plugin\\Storage\\Audit_Log_Table;', $migrationContents);
		$this->assertStringContainsString("public const string ID = '2026_06_26_000002_create_audit_log_table';", $migrationContents);
	}

	public function test_database_alteration_migrations_accept_generation_options(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute([
			'name'        => 'Add_Status_To_Audit_Log',
			'--namespace' => 'Acme\\Plugin\\Storage\\Migrations',
			'--path'      => 'custom/migrations',
			'--id'        => '2026_06_26_000003_add_status_to_audit_log',
			'--table'     => 'Acme\\Plugin\\Storage\\Audit_Log_Table',
		]);

		$contents = (string) file_get_contents($root . '/custom/migrations/Add_Status_To_Audit_Log.php');

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('namespace Acme\\Plugin\\Storage\\Migrations;', $contents);
		$this->assertStringContainsString('use Acme\\Plugin\\Storage\\Audit_Log_Table;', $contents);
		$this->assertStringContainsString('$blueprint = Blueprint::for( $this->table );', $contents);
		$this->assertStringContainsString('$schema->alter( $blueprint );', $contents);
		$this->assertStringNotContainsString('$schema->drop( $this->table );', $contents);
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
		$this->assertFalse($this->providerCommand($root)->getDefinition()->hasOption('force'));
		$this->assertStringContainsString('namespace Acme\\Plugin\\Storage;', $contents);
		$this->assertStringContainsString('final class Database_Provider extends Service_Provider {', $contents);
		$this->assertStringContainsString('private bool $registered = false;', $contents);
		$this->assertStringContainsString('if ( $this->registered ) {', $contents);
	}

	public function test_database_provider_generator_refuses_to_replace_an_existing_provider(): void {
		$root    = $this->temporaryProject();
		$command = $this->providerCommand($root);

		$this->assertSame(Command::SUCCESS, (new CommandTester($command))->execute([]));

		$path     = $root . '/src/Database/Provider.php';
		$contents = (string) file_get_contents($path);
		$tester   = new CommandTester($command);

		$this->assertSame(Command::FAILURE, $tester->execute([]));
		$this->assertStringContainsString('File already exists: src/Database/Provider.php.', $tester->getDisplay());
		$this->assertSame($contents, file_get_contents($path));
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
			'name'     => 'create-reports-table',
			'--create' => 'Reports_Table',
			'--id'     => '2026_06_26_000001_create_reports_table',
		]);

		$contents = (string) file_get_contents($root . '/src/Database/Provider.php');

		$this->assertSame(Command::SUCCESS, $tableStatus);
		$this->assertStringContainsString('Updated: src/Database/Provider.php', $tableTester->getDisplay());
		$this->assertSame(Command::SUCCESS, $migrationStatus);
		$this->assertStringContainsString('Updated: src/Database/Provider.php', $migrationTester->getDisplay());
		$this->assertStringNotContainsString('Register this migration', $migrationTester->getDisplay());
		$this->assertStringContainsString('use Acme\\Plugin\\Database\\Tables\\Reports_Table;', $contents);
		$this->assertStringContainsString('use Acme\\Plugin\\Database\\Migrations\\Create_Reports_Table;', $contents);
		$this->assertStringContainsString('$this->container->singleton( Reports_Table::class );', $contents);
		$this->assertStringContainsString('$c->get( Create_Reports_Table::class ),', $contents);
		$this->assertStringContainsString("\t\t\$this->container->singleton( Reports_Table::class );\n\t\t// foundation:database-tables", $contents);
		$this->assertStringContainsString("\t\t\t\$c->get( Create_Reports_Table::class ),\n\t\t] );", $contents);
		$this->assertStringNotContainsString('Array$this', $contents);
		$this->assertStringNotContainsString('Array$c', $contents);
	}

	public function test_database_migration_generator_appends_to_existing_provider_migrations_in_order(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		(new CommandTester($this->providerCommand($root)))->execute([]);

		(new CommandTester($this->migrationCommand($root)))->execute([
			'name'     => 'create-reports-table',
			'--create' => 'Reports_Table',
			'--id'     => '2026_06_26_000001_create_reports_table',
		]);
		(new CommandTester($this->migrationCommand($root)))->execute([
			'name'     => 'create-orders-table',
			'--create' => 'Orders_Table',
			'--id'     => '2026_06_26_000002_create_orders_table',
		]);

		$contents = (string) file_get_contents($root . '/src/Database/Provider.php');

		$reportsOffset = strpos($contents, '$c->get( Create_Reports_Table::class ),');
		$ordersOffset  = strpos($contents, '$c->get( Create_Orders_Table::class ),');

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
			'--create'   => 'Reports_Table',
			'--id'       => '2026_06_26_000001_create_reports_table',
			'--provider' => 'custom/providers/Provider.php',
		]);

		$contents = (string) file_get_contents($root . '/custom/providers/Provider.php');

		$this->assertSame(Command::SUCCESS, $tableStatus);
		$this->assertStringContainsString('Updated: custom/providers/Provider.php', $tableTester->getDisplay());
		$this->assertSame(Command::SUCCESS, $migrationStatus);
		$this->assertStringContainsString('Updated: custom/providers/Provider.php', $migrationTester->getDisplay());
		$this->assertStringContainsString('$this->container->singleton( Reports_Table::class );', $contents);
		$this->assertStringContainsString('$c->get( Create_Reports_Table::class ),', $contents);
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

	public function test_explicit_database_provider_updates_fail_before_writing_when_the_provider_is_not_writable(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		(new CommandTester($this->providerCommand($root)))->execute([]);

		$providerPath = $root . '/src/Database/Provider.php';
		chmod($providerPath, 0444);

		$tableTester = new CommandTester($this->tableCommand($root));
		$tableStatus = $tableTester->execute([
			'name'       => 'reports',
			'--provider' => 'src/Database/Provider.php',
		]);

		$migrationTester = new CommandTester($this->migrationCommand($root));
		$migrationStatus = $migrationTester->execute([
			'name'       => 'create-reports-table',
			'--create'   => 'Reports_Table',
			'--provider' => 'src/Database/Provider.php',
		]);

		$this->assertSame(Command::FAILURE, $tableStatus);
		$this->assertSame(Command::FAILURE, $migrationStatus);
		$this->assertStringContainsString('file is not writable', $tableTester->getDisplay());
		$this->assertStringContainsString('file is not writable', $migrationTester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Tables/Reports_Table.php');
		$this->assertFileDoesNotExist($root . '/src/Database/Migrations/Create_Reports_Table.php');
	}

	public function test_combined_generation_fails_before_writing_when_the_provider_directory_cannot_be_replaced(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^2.0',
			],
		]);

		(new CommandTester($this->providerCommand($root)))->execute([]);

		$providerDirectory = $root . '/src/Database';
		$tester            = new CommandTester($this->tableCommand($root));

		chmod($providerDirectory, 0555);

		try {
			$status = $tester->execute([
				'name'        => 'reports',
				'--migration' => true,
				'--provider'  => 'src/Database/Provider.php',
			]);
		} finally {
			chmod($providerDirectory, 0755);
		}

		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringContainsString('file is not writable', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Tables/Reports_Table.php');
		$this->assertFileDoesNotExist($root . '/src/Database/Migrations/Create_Reports_Table.php');
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_migration_generation_removes_its_file_when_provider_replacement_fails_after_preflight(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^2.0',
			],
		]);

		(new CommandTester($this->providerCommand($root)))->execute([]);

		$providerPath = $root . '/src/Database/Provider.php';
		$original     = (string) file_get_contents($providerPath);

		PHPMockery::mock('StellarWP\Foundation\Cli\Commands\Make\Database', 'rename')
			->once()
			->andReturn(false);

		$tester = new CommandTester($this->migrationCommand($root));
		$status = $tester->execute([
			'name'       => 'create-reports-table',
			'--create'   => 'Reports_Table',
			'--provider' => 'src/Database/Provider.php',
		]);

		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringContainsString('file could not be written', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Migrations/Create_Reports_Table.php');
		$this->assertSame($original, (string) file_get_contents($providerPath));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_combined_generation_removes_both_files_when_provider_replacement_fails_after_preflight(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^2.0',
			],
		]);

		(new CommandTester($this->providerCommand($root)))->execute([]);

		$providerPath = $root . '/src/Database/Provider.php';
		$original     = (string) file_get_contents($providerPath);

		PHPMockery::mock('StellarWP\Foundation\Cli\Commands\Make\Database', 'tempnam')
			->with(dirname($providerPath), '.foundation-provider-')
			->once()
			->andReturn(false);

		$tester = new CommandTester($this->tableCommand($root));
		$status = $tester->execute([
			'name'        => 'reports',
			'--migration' => true,
			'--provider'  => 'src/Database/Provider.php',
		]);

		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringContainsString('file could not be written', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Tables/Reports_Table.php');
		$this->assertFileDoesNotExist($root . '/src/Database/Migrations/Create_Reports_Table.php');
		$this->assertSame($original, (string) file_get_contents($providerPath));
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
			'--create'   => 'Reports_Table',
			'--id'       => '2026_06_26_000001_create_reports_table',
			'--provider' => 'custom/providers/Provider.php',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('file does not contain a generated database provider registration point', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Migrations/Create_Reports_Table.php');
	}

	public function test_table_generator_warns_when_the_conventional_provider_cannot_be_updated(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		mkdir($root . '/src/Database', 0777, true);
		file_put_contents(
			$root . '/src/Database/Provider.php',
			file_get_contents($this->data_dir('cli/generation/php-source-editor/database-provider-without-registration-points.stub'))
		);

		$tester     = new CommandTester($this->tableCommand($root));
		$statusCode = $tester->execute(['name' => 'reports']);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertFileExists($root . '/src/Database/Tables/Reports_Table.php');
		$this->assertStringContainsString('Provider not updated: src/Database/Provider.php', $tester->getDisplay());
		$this->assertStringContainsString('Register Reports_Table manually.', $tester->getDisplay());
	}

	public function test_migration_generator_warns_when_the_conventional_provider_cannot_be_updated(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		mkdir($root . '/src/Database', 0777, true);
		file_put_contents(
			$root . '/src/Database/Provider.php',
			file_get_contents($this->data_dir('cli/generation/php-source-editor/database-provider-without-registration-points.stub'))
		);

		$tester     = new CommandTester($this->migrationCommand($root));
		$statusCode = $tester->execute([
			'name'     => 'create-reports-table',
			'--create' => 'Reports_Table',
			'--id'     => '2026_06_26_000001_create_reports_table',
		]);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertFileExists($root . '/src/Database/Migrations/Create_Reports_Table.php');
		$this->assertStringContainsString('Provider not updated: src/Database/Provider.php', $tester->getDisplay());
		$this->assertStringContainsString('Register Create_Reports_Table manually.', $tester->getDisplay());
	}

	public function test_database_provider_migration_update_preserves_legacy_migration_marker_position(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Database', 0777, true);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, <<<'PHP'
<?php declare(strict_types=1);

namespace Acme\Plugin\Database;

use StellarWP\Foundation\Container\Contracts\Resolver as C;
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

		$this->assertTrue($status->wasUpdated());
		$this->assertStringContainsString('use Acme\\Plugin\\Database\\Migrations\\Create_Reports_Table;', $contents);
		$this->assertStringContainsString("\t\t\t\$c->get( Create_Reports_Table::class ),\n\t\t\t// foundation:database-migrations", $contents);
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

		$this->assertTrue($status->wasUpdated());
		$this->assertStringContainsString('$this->container->get( Create_Reports_Table::class ),', $contents);
	}

	public function test_combined_provider_update_does_not_write_the_table_when_the_migration_cannot_be_added(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Database', 0777, true);

		$providerPath = $root . '/src/Database/Provider.php';
		$original     = (string) file_get_contents(
			$this->data_dir('cli/generation/php-source-editor/database-provider-with-table-marker-only.stub')
		);

		file_put_contents($providerPath, $original);

		$status = $this->providerUpdater()->addTableAndMigration(
			providerPath: $providerPath,
			tableClass: 'Reports_Table',
			tableNamespace: 'Acme\\Plugin\\Database\\Tables',
			migrationClass: 'Create_Reports_Table',
			migrationNamespace: 'Acme\\Plugin\\Database\\Migrations'
		);

		$this->assertSame('file does not contain a generated database provider registration point', $status->failureReason());
		$this->assertSame($original, (string) file_get_contents($providerPath));
		$this->assertSame(
			'file does not contain a generated database provider registration point',
			$this->providerUpdater()->checkTableAndMigration(
				providerPath: $providerPath,
				tableClass: 'Reports_Table',
				tableNamespace: 'Acme\\Plugin\\Database\\Tables',
				migrationClass: 'Create_Reports_Table',
				migrationNamespace: 'Acme\\Plugin\\Database\\Migrations'
			)->failureReason()
		);
	}

	public function test_combined_provider_updates_are_idempotent(): void {
		$root = $this->temporaryProject();

		(new CommandTester($this->providerCommand($root)))->execute([]);

		$providerPath = $root . '/src/Database/Provider.php';
		$updater      = $this->providerUpdater();
		$arguments    = [
			'providerPath'       => $providerPath,
			'tableClass'         => 'Reports_Table',
			'tableNamespace'     => 'Acme\\Plugin\\Database\\Tables',
			'migrationClass'     => 'Create_Reports_Table',
			'migrationNamespace' => 'Acme\\Plugin\\Database\\Migrations',
		];

		$this->assertTrue($updater->addTableAndMigration(...$arguments)->wasUpdated());

		$updated = (string) file_get_contents($providerPath);

		$this->assertTrue($updater->addTableAndMigration(...$arguments)->wasAlreadyRegistered());
		$this->assertTrue($updater->checkTableAndMigration(...$arguments)->wasAlreadyRegistered());
		$this->assertSame($updated, (string) file_get_contents($providerPath));
	}

	public function test_provider_registration_checks_report_ready_without_changing_the_provider(): void {
		$root = $this->temporaryProject();

		(new CommandTester($this->providerCommand($root)))->execute([]);

		$providerPath = $root . '/src/Database/Provider.php';
		$updater      = $this->providerUpdater();
		$original     = (string) file_get_contents($providerPath);

		$tableResult = $updater->checkTable(
			$providerPath,
			'Reports_Table',
			'Acme\\Plugin\\Database\\Tables'
		);
		$migrationResult = $updater->checkMigration(
			$providerPath,
			'Create_Reports_Table',
			'Acme\\Plugin\\Database\\Migrations'
		);

		$this->assertTrue($tableResult->succeeded());
		$this->assertFalse($tableResult->wasUpdated());
		$this->assertFalse($tableResult->wasAlreadyRegistered());
		$this->assertTrue($migrationResult->succeeded());
		$this->assertFalse($migrationResult->wasUpdated());
		$this->assertFalse($migrationResult->wasAlreadyRegistered());
		$this->assertSame($original, (string) file_get_contents($providerPath));

		$this->assertTrue($updater->addTable(
			$providerPath,
			'Reports_Table',
			'Acme\\Plugin\\Database\\Tables'
		)->wasUpdated());

		$partiallyRegistered = (string) file_get_contents($providerPath);
		$combinedResult      = $updater->checkTableAndMigration(
			providerPath: $providerPath,
			tableClass: 'Reports_Table',
			tableNamespace: 'Acme\\Plugin\\Database\\Tables',
			migrationClass: 'Create_Reports_Table',
			migrationNamespace: 'Acme\\Plugin\\Database\\Migrations'
		);

		$this->assertTrue($combinedResult->succeeded());
		$this->assertFalse($combinedResult->wasUpdated());
		$this->assertFalse($combinedResult->wasAlreadyRegistered());
		$this->assertSame($partiallyRegistered, (string) file_get_contents($providerPath));
	}

	public function test_provider_updates_preserve_symbolic_links(): void {
		$root = $this->temporaryProject();

		(new CommandTester($this->providerCommand($root)))->execute([]);

		$providerPath = $root . '/src/Database/Provider.php';
		$linkPath     = $root . '/DatabaseProvider.php';

		$this->assertTrue(symlink($providerPath, $linkPath));
		$this->assertTrue(
			$this->providerUpdater()->addTable(
				$linkPath,
				'Reports_Table',
				'Acme\\Plugin\\Database\\Tables'
			)->wasUpdated()
		);

		$this->assertTrue(is_link($linkPath));
		$this->assertStringContainsString(
			'$this->container->singleton( Reports_Table::class );',
			(string) file_get_contents($providerPath)
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_provider_updates_report_transient_read_failures(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Database', 0777, true);

		$providerPath = $root . '/src/Database/Provider.php';

		file_put_contents($providerPath, '<?php // provider');

		PHPMockery::mock('StellarWP\Foundation\Cli\Commands\Make\Database', 'file_get_contents')
			->with($providerPath)
			->times(3)
			->andReturn(false);

		$updater = $this->providerUpdater();

		$this->assertSame(
			'file could not be read',
			$updater->checkTable($providerPath, 'Reports_Table', 'Acme\\Plugin\\Database\\Tables')->failureReason()
		);
		$this->assertSame(
			'file could not be read',
			$updater->checkMigration($providerPath, 'Create_Reports_Table', 'Acme\\Plugin\\Database\\Migrations')->failureReason()
		);
		$this->assertSame(
			'file could not be read',
			$updater->addTableAndMigration(
				providerPath: $providerPath,
				tableClass: 'Reports_Table',
				tableNamespace: 'Acme\\Plugin\\Database\\Tables',
				migrationClass: 'Create_Reports_Table',
				migrationNamespace: 'Acme\\Plugin\\Database\\Migrations'
			)->failureReason()
		);
	}

	public function test_provider_updates_report_a_missing_provider_file(): void {
		$providerPath = $this->tempDir . '/missing/Provider.php';
		$updater      = $this->providerUpdater();

		$this->assertSame(
			'file does not exist or is not readable',
			$updater->checkTable($providerPath, 'Reports_Table', 'Acme\\Plugin\\Database\\Tables')->failureReason()
		);
		$this->assertSame(
			'file does not exist or is not readable',
			$updater->checkMigration($providerPath, 'Create_Reports_Table', 'Acme\\Plugin\\Database\\Migrations')->failureReason()
		);
		$this->assertSame(
			'file does not exist or is not readable',
			$updater->addTableAndMigration(
				providerPath: $providerPath,
				tableClass: 'Reports_Table',
				tableNamespace: 'Acme\\Plugin\\Database\\Tables',
				migrationClass: 'Create_Reports_Table',
				migrationNamespace: 'Acme\\Plugin\\Database\\Migrations'
			)->failureReason()
		);
	}

	public function test_database_provider_migration_update_supports_closure_callbacks_returning_arrays(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Database', 0777, true);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, <<<'PHP'
<?php declare(strict_types=1);

namespace Acme\Plugin\Database;

use StellarWP\Foundation\Container\Contracts\Resolver as C;
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

		$this->assertTrue($status->wasUpdated());
		$this->assertStringContainsString('use Acme\\Plugin\\Database\\Migrations\\Create_Reports_Table;', $contents);
		$this->assertStringContainsString("\t\t\t\t\$c->get( Create_Reports_Table::class ),\n\t\t\t];", $contents);
	}

	public function test_database_provider_migration_update_uses_the_callback_parameter_name(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Database', 0777, true);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, <<<'PHP'
<?php declare(strict_types=1);

namespace Acme\Plugin\Database;

use StellarWP\Foundation\Container\Contracts\Resolver as C;
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

		$this->assertTrue($status->wasUpdated());
		$this->assertStringContainsString('$container->get( Create_Reports_Table::class ),', $contents);
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

use StellarWP\Foundation\Container\Contracts\Resolver as C;
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
			'--create'   => 'Reports_Table',
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
			'--create'   => 'Reports_Table',
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
use StellarWP\Foundation\Container\Contracts\Resolver as C;

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

		$this->assertSame('file does not contain a generated database provider registration point', $status->failureReason());
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
		$this->assertSame(1, substr_count($contents, '$this->container->singleton( Reports_Table::class );'));
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

		$this->assertTrue($status->wasUpdated());
		$this->assertStringContainsString("namespace Acme\\Plugin\\Database;\n\nuse Acme\\Plugin\\Database\\Tables\\Reports_Table;\n\nfinal class Provider", $contents);
		$this->assertStringContainsString("\t\t\$this->container->singleton( Reports_Table::class );\n\t\t// foundation:database-tables", $contents);
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

		$this->assertTrue($status->wasUpdated());
		$this->assertStringContainsString('use Acme\\Plugin\\Database\\Tables\\Reports_Table as Existing_Reports_Table;', $contents);
		$this->assertStringContainsString('use Acme\\Plugin\\Database\\Tables\\Reports_Table;', $contents);
		$this->assertStringContainsString("\t\t\$this->container->singleton( Reports_Table::class );\n\t\t// foundation:database-tables", $contents);
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

		$this->assertTrue($status->wasUpdated());
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

		$this->assertSame('file does not contain the generated database provider markers', $status->failureReason());
		$this->assertSame(0, substr_count((string) file_get_contents($providerPath), '$this->container->singleton( Reports_Table::class );'));
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

		$this->assertTrue($status->wasAlreadyRegistered());
		$this->assertSame($contents, (string) file_get_contents($providerPath));
	}

	public function test_database_provider_updater_is_idempotent_after_wordpress_formatting(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Database', 0777, true);

		$providerPath = $root . '/src/Database/Provider.php';
		$contents     = (string) file_get_contents($this->data_dir('cli/generation/php-source-editor/formatted-database-provider.stub'));
		file_put_contents($providerPath, $contents);

		$tableStatus = $this->providerUpdater()->addTable(
			providerPath: $providerPath,
			class: 'Reports_Table',
			classNamespace: 'Acme\\Plugin\\Database\\Tables'
		);
		$migrationStatus = $this->providerUpdater()->addMigration(
			providerPath: $providerPath,
			class: 'Create_Reports_Table',
			classNamespace: 'Acme\\Plugin\\Database\\Migrations'
		);

		$this->assertTrue($tableStatus->wasAlreadyRegistered());
		$this->assertTrue($migrationStatus->wasAlreadyRegistered());
		$this->assertSame($contents, (string) file_get_contents($providerPath));
	}

	public function test_database_provider_updater_is_idempotent_for_namespace_relative_registrations(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Database', 0777, true);

		$providerPath = $root . '/src/Database/Provider.php';
		$contents     = (string) file_get_contents($this->data_dir('cli/generation/php-source-editor/namespace-relative-registrations.stub'));
		file_put_contents($providerPath, $contents);

		$tableStatus = $this->providerUpdater()->addTable(
			providerPath: $providerPath,
			class: 'Reports_Table',
			classNamespace: 'Acme\\Plugin\\Database\\Tables'
		);
		$migrationStatus = $this->providerUpdater()->addMigration(
			providerPath: $providerPath,
			class: 'Create_Reports_Table',
			classNamespace: 'Acme\\Plugin\\Database\\Migrations'
		);

		$this->assertTrue($tableStatus->wasAlreadyRegistered());
		$this->assertTrue($migrationStatus->wasAlreadyRegistered());
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
			"use Acme\\Other\\reports_table;\nuse StellarWP\\Foundation\\Database\\DatabaseProvider;",
			(string) file_get_contents($providerPath)
		));

		$tester     = new CommandTester($this->tableCommand($root));
		$statusCode = $tester->execute([
			'name'       => 'reports',
			'--provider' => 'src/Database/Provider.php',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('another class declaration or import uses the same short class name', $tester->getDisplay());
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
		$this->assertStringContainsString('another class declaration or import uses the same short class name', $tester->getDisplay());
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
		$this->assertStringContainsString('another class declaration or import uses the same short class name', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Tables/Reports_Table.php');
	}

	public function test_explicit_database_provider_update_fails_when_the_generated_class_matches_the_provider_class(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		(new CommandTester($this->providerCommand($root)))->execute([]);

		$providerPath     = $root . '/src/Database/Provider.php';
		$providerContents = (string) file_get_contents($providerPath);
		$tester           = new CommandTester($this->migrationCommand($root));
		$statusCode       = $tester->execute([
			'name'       => 'Provider',
			'--provider' => 'src/Database/Provider.php',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('another class declaration or import uses the same short class name', $tester->getDisplay());
		$this->assertSame($providerContents, (string) file_get_contents($providerPath));
		$this->assertFileDoesNotExist($root . '/src/Database/Migrations/Provider.php');
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
			'name'     => 'create-reports-table',
			'--create' => 'Reports_Table',
			'--id'     => '2026_06_26_000001_create_reports_table',
		]);
		$migrationTester->execute([
			'name'    => 'add-status-to-reports',
			'--id'    => '2026_06_26_000002_add_status_to_reports',
			'--table' => 'Reports_Table',
		]);
		$migrationTester->execute([
			'name' => 'bump-version',
			'--id' => '2026_06_26_000003_bump_version',
		]);

		$tableContents     = (string) file_get_contents($root . '/src/Database/Tables/Reports_Table.php');
		$migrationContents = (string) file_get_contents($root . '/src/Database/Migrations/Create_Reports_Table.php');
		$alterContents     = (string) file_get_contents($root . '/src/Database/Migrations/Add_Status_To_Reports.php');
		$genericContents   = (string) file_get_contents($root . '/src/Database/Migrations/Bump_Version.php');

		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Database\\Table\\Table;', $tableContents);
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Database\\Contracts\\Migration;', $migrationContents);
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Database\\Contracts\\Schema;', $migrationContents);
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Database\\Table\\Blueprint;', $migrationContents);
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Database\\Migration\\Exceptions\\IrreversibleMigration;', $alterContents);
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Database\\Contracts\\Migration;', $alterContents);
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Database\\Contracts\\Schema;', $alterContents);
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Database\\Table\\Blueprint;', $alterContents);
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Database\\Migration\\Exceptions\\IrreversibleMigration;', $genericContents);
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Database\\Contracts\\Migration;', $genericContents);
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Database\\Contracts\\Schema;', $genericContents);
		$this->assertStringNotContainsString('use StellarWP\\Foundation\\Database\\Contracts\\Database;', $tableContents);
		$this->assertStringNotContainsString('use StellarWP\\Foundation\\Database\\Table\\Table;', $tableContents);
		$this->assertStringNotContainsString('use StellarWP\\Foundation\\Database\\Table\\Blueprint;', $tableContents);
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
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Container\\Contracts\\Resolver as C;', $contents);
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Database\\DatabaseProvider;', $contents);
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Container\\Contracts\\Provider as Service_Provider;', $contents);
		$this->assertStringNotContainsString('use StellarWP\\Foundation\\Container\\Contracts\\Resolver as C;', $contents);
		$this->assertStringNotContainsString('use StellarWP\\Foundation\\Database\\DatabaseProvider;', $contents);
	}

	public function test_database_generators_use_project_stub_overrides(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/foundation/stubs/database', 0777, true);
		file_put_contents($root . '/foundation/stubs/database/table.stub', '<?php namespace {{ namespace }}; // Generated table {{ class }} in {{ namespace }}' . "\n" . 'final class {{ class }} {}');
		file_put_contents($root . '/foundation/stubs/database/create-table-migration.stub', '<?php namespace {{ namespace }}; // Generated create migration {{ class }} with {{ table_class }}' . "\n" . 'final class {{ class }} {}');
		file_put_contents($root . '/foundation/stubs/database/alter-table-migration.stub', '<?php namespace {{ namespace }}; // Generated alteration migration {{ class }} with {{ table_class }}' . "\n" . 'final class {{ class }} {}');
		file_put_contents($root . '/foundation/stubs/database/migration.stub', '<?php namespace {{ namespace }}; // Generated migration {{ class }}' . "\n" . 'final class {{ class }} {}');
		file_put_contents($root . '/foundation/stubs/database/provider.stub', '<?php namespace {{ namespace }}; // Generated provider {{ class }} in {{ namespace }}' . "\n" . 'final class {{ class }} {}');

		(new CommandTester($this->tableCommand($root)))->execute([
			'name' => 'reports',
		]);
		(new CommandTester($this->migrationCommand($root)))->execute([
			'name'     => 'create-reports-table',
			'--create' => 'Reports_Table',
			'--id'     => '2026_06_26_000001_create_reports_table',
		]);
		(new CommandTester($this->migrationCommand($root)))->execute([
			'name' => 'bump-version',
			'--id' => '2026_06_26_000003_bump_version',
		]);
		(new CommandTester($this->migrationCommand($root)))->execute([
			'name'    => 'add-status-to-reports',
			'--id'    => '2026_06_26_000004_add_status_to_reports',
			'--table' => 'Reports_Table',
		]);
		(new CommandTester($this->providerCommand($root)))->execute([]);

		$this->assertStringContainsString(
			'Generated table Reports_Table in Acme\\Plugin\\Database\\Tables',
			(string) file_get_contents($root . '/src/Database/Tables/Reports_Table.php')
		);
		$this->assertStringContainsString(
			'Generated create migration Create_Reports_Table with Reports_Table',
			(string) file_get_contents($root . '/src/Database/Migrations/Create_Reports_Table.php')
		);
		$this->assertStringContainsString(
			'Generated alteration migration Add_Status_To_Reports with Reports_Table',
			(string) file_get_contents($root . '/src/Database/Migrations/Add_Status_To_Reports.php')
		);
		$this->assertStringContainsString(
			'Generated migration Bump_Version',
			(string) file_get_contents($root . '/src/Database/Migrations/Bump_Version.php')
		);
		$this->assertStringContainsString(
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
			'name'     => 'create-reports-table',
			'--create' => 'Reports_Table',
			'--id'     => '2026_06_26_000001_create_reports_table',
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
			'name'     => 'create-reports-table',
			'--create' => 'Reports_Table',
			'--id'     => '2026_06_26_000001_create_reports_table',
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
			'--create'    => 'Reports_Table',
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

	/**
	 * @dataProvider invalidMigrationIdProvider
	 */
	#[DataProvider('invalidMigrationIdProvider')]
	public function test_database_migration_generator_rejects_runtime_invalid_ids(string $id, string $message): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute([
			'name' => 'bump-version',
			'--id' => $id,
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString($message, $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Migrations/Bump_Version.php');
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function invalidMigrationIdProvider(): iterable {
		yield 'blank' => ['', 'cannot be blank'];

		yield 'padded' => [' padded ', 'surrounding whitespace'];

		yield 'integer-like' => ['123', 'integer-like'];

		yield 'over ledger limit' => [str_repeat('a', 192), 'cannot exceed 191 bytes'];
	}

	public function test_database_migration_generator_rejects_an_overlong_generated_id(): void {
		$root   = $this->temporaryProject();
		$name   = str_repeat('a', 180);
		$tester = new CommandTester($this->migrationCommand($root));

		$statusCode = $tester->execute(['name' => $name]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('cannot exceed 191 bytes', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Database/Migrations/' . ucfirst($name) . '.php');
	}

	public function test_database_generators_reject_class_names_that_conflict_with_stub_imports(): void {
		$root  = $this->temporaryProject();
		$cases = [
			[new CommandTester($this->tableCommand($root)), ['name' => 'Table'], 'src/Database/Tables/Table.php'],
			[new CommandTester($this->migrationCommand($root)), ['name' => 'Migration'], 'src/Database/Migrations/Migration.php'],
			[
				new CommandTester($this->migrationCommand($root)),
				['name' => 'Reports_Table', '--table' => 'Reports_Table'],
				'src/Database/Migrations/Reports_Table.php',
			],
			[new CommandTester($this->providerCommand($root)), ['name' => 'C'], 'src/Database/C.php'],
		];

		foreach ($cases as [$tester, $input, $relativePath]) {
			$this->assertSame(Command::FAILURE, $tester->execute($input));
			$this->assertStringContainsString('declares or imports', $tester->getDisplay());
			$this->assertFileDoesNotExist($root . '/' . $relativePath);
		}
	}

	private function tableCommand(string $root): TableCommand {
		$projectDirectory = new ProjectDirectory($root);

		return new TableCommand(
			projectDirectory: $projectDirectory,
			autoloadResolver: new ComposerAutoloadResolver($projectDirectory),
			classNameResolver: new WordPressClassNameResolver(),
			stubResolver: new StubResolver($projectDirectory),
			stubRenderer: new StubRenderer(),
			fileWriter: $this->fileWriter(),
			providerUpdater: $this->providerUpdater(),
			migrationFactory: $this->migrationFactory($root)
		);
	}

	private function migrationCommand(string $root): MigrationCommand {
		$projectDirectory = new ProjectDirectory($root);

		return new MigrationCommand(
			projectDirectory: $projectDirectory,
			autoloadResolver: new ComposerAutoloadResolver($projectDirectory),
			migrationFactory: $this->migrationFactory($root),
			fileWriter: $this->fileWriter(),
			providerUpdater: $this->providerUpdater()
		);
	}

	private function migrationFactory(string $root): MigrationFileFactory {
		$projectDirectory = new ProjectDirectory($root);

		return new MigrationFileFactory(
			projectDirectory: $projectDirectory,
			autoloadResolver: new ComposerAutoloadResolver($projectDirectory),
			classNameResolver: new WordPressClassNameResolver(),
			stubResolver: new StubResolver($projectDirectory),
			stubRenderer: new StubRenderer()
		);
	}

	private function providerCommand(string $root): ProviderCommand {
		$projectDirectory = new ProjectDirectory($root);

		return new ProviderCommand(
			projectDirectory: $projectDirectory,
			autoloadResolver: new ComposerAutoloadResolver($projectDirectory),
			classNameResolver: new WordPressClassNameResolver(),
			stubResolver: new StubResolver($projectDirectory),
			stubRenderer: new StubRenderer(),
			fileWriter: $this->fileWriter()
		);
	}

	private function fileWriter(): GeneratedFileWriter {
		return new GeneratedFileWriter(new PhpSourceEditor(new ParserFactory(), new Lexer()));
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
