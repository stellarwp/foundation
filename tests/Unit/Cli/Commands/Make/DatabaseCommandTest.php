<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Commands\Make;

use StellarWP\Foundation\Cli\Commands\Make\DatabaseMigrationCommand;
use StellarWP\Foundation\Cli\Commands\Make\DatabaseTableCommand;
use StellarWP\Foundation\Cli\Generation\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
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

	public function test_database_generators_use_project_stub_overrides(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/foundation/stubs/database', 0777, true);
		file_put_contents($root . '/foundation/stubs/database/table.stub', 'Generated table {{ class }} in {{ namespace }}');
		file_put_contents($root . '/foundation/stubs/database/migration.stub', 'Generated migration {{ class }} with {{ table_class }}');

		(new CommandTester($this->tableCommand($root)))->execute([
			'name' => 'reports',
		]);
		(new CommandTester($this->migrationCommand($root)))->execute([
			'name' => 'create-reports-table',
			'--id' => '2026_06_26_000001_create_reports_table',
		]);

		$this->assertSame(
			'Generated table Reports_Table in Acme\\Plugin\\Database\\Tables',
			(string) file_get_contents($root . '/src/Database/Tables/Reports_Table.php')
		);
		$this->assertSame(
			'Generated migration Create_Reports_Table with Reports_Table',
			(string) file_get_contents($root . '/src/Database/Migrations/Create_Reports_Table.php')
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

	private function tableCommand(string $root): DatabaseTableCommand {
		return new DatabaseTableCommand(
			rootPath: $root,
			autoloadResolver: new ComposerAutoloadResolver($root),
			classNameResolver: new WordPressClassNameResolver(),
			stubResolver: new StubResolver($root),
			stubRenderer: new StubRenderer(),
			fileWriter: new GeneratedFileWriter()
		);
	}

	private function migrationCommand(string $root): DatabaseMigrationCommand {
		return new DatabaseMigrationCommand(
			rootPath: $root,
			autoloadResolver: new ComposerAutoloadResolver($root),
			classNameResolver: new WordPressClassNameResolver(),
			stubResolver: new StubResolver($root),
			stubRenderer: new StubRenderer(),
			fileWriter: new GeneratedFileWriter()
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
