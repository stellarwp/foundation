<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Commands\Make;

use phpmock\mockery\PHPMockery;
use PhpParser\Lexer;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use StellarWP\Foundation\Cli\CliProvider;
use StellarWP\Foundation\Cli\Commands\Make\Database\Factories\MigrationFileFactory;
use StellarWP\Foundation\Cli\Commands\Make\Database\ProviderCommand;
use StellarWP\Foundation\Cli\Commands\Make\Database\ProviderFileResolver;
use StellarWP\Foundation\Cli\Commands\Make\Database\ProviderRegistrationEditor;
use StellarWP\Foundation\Cli\Commands\Make\Database\TableCommand;
use StellarWP\Foundation\Cli\Composer\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
use StellarWP\Foundation\Cli\Generation\GeneratorLocationResolver;
use StellarWP\Foundation\Cli\Generation\Php\PhpSourceEditor;
use StellarWP\Foundation\Cli\Generation\StubRenderer;
use StellarWP\Foundation\Cli\Generation\StubResolver;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use StellarWP\Foundation\Cli\Generation\WordPressClassNameResolver;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
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

	/**
	 * @var array<string, mixed>
	 */
	private array $generatorConfig = [];

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
		$this->assertStringContainsString('Create a migration that defines this table with Blueprint::create().', $tester->getDisplay());

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
		$this->assertStringContainsString('use StellarWP\\Foundation\\Container\\Contracts\\Provider as Service_Provider;', $contents);
		$this->assertStringContainsString('final class Provider extends Service_Provider {', $contents);
		$this->assertStringContainsString('$this->register_tables();', $contents);
		$this->assertStringContainsString('// foundation:database-tables', $contents);
		$this->assertStringNotContainsString('// foundation:database-migrations', $contents);
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
		$this->assertStringNotContainsString('private bool $registered = false;', $contents);
		$this->assertStringNotContainsString('if ( $this->registered ) {', $contents);
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

	public function test_explicit_database_provider_update_fails_on_import_short_name_collisions(): void {
		$root = $this->temporaryProject([
			'require' => [
				'stellarwp/foundation-database' => '^1.2',
			],
		]);

		(new CommandTester($this->providerCommand($root)))->execute([]);

		$providerPath = $root . '/src/Database/Provider.php';
		file_put_contents($providerPath, str_replace(
			'use StellarWP\\Foundation\\Container\\Contracts\\Provider as Service_Provider;',
			"use Acme\\Other\\reports_table;\nuse StellarWP\\Foundation\\Container\\Contracts\\Provider as Service_Provider;",
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
			'use StellarWP\\Foundation\\Container\\Contracts\\Provider as Service_Provider;',
			"use Acme\\Other\\{Reports as Reports_Table};\nuse StellarWP\\Foundation\\Container\\Contracts\\Provider as Service_Provider;",
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
			'use StellarWP\\Foundation\\Container\\Contracts\\Provider as Service_Provider;',
			"use Acme\\Other\\Reports as Reports_Table;\nuse StellarWP\\Foundation\\Container\\Contracts\\Provider as Service_Provider;",
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
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\Container\\Contracts\\Provider as Service_Provider;', $contents);
		$this->assertStringNotContainsString('use StellarWP\\Foundation\\Container\\Contracts\\Resolver as C;', $contents);
		$this->assertStringNotContainsString('use StellarWP\\Foundation\\Container\\Contracts\\Provider as Service_Provider;', $contents);
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
			->with(dirname($providerPath), '.foundation-write-')
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
		$this->assertSame([], glob($root . '/src/Database/Migrations/Migration_*.php'));
		$this->assertSame($original, (string) file_get_contents($providerPath));
	}

	private function generatorLocations(ProjectDirectory $projectDirectory): GeneratorLocationResolver {
		$container = (new ContainerFactory())->create(new ArrayConfiguration($this->generatorConfig));
		$container->register(CliProvider::class);
		$container->singleton(ProjectDirectory::class, $projectDirectory);

		return $container->get(GeneratorLocationResolver::class);
	}

	private function tableCommand(string $root): TableCommand {
		$projectDirectory = new ProjectDirectory($root);

		return new TableCommand(
			providerFiles: new ProviderFileResolver($projectDirectory, $this->generatorLocations($projectDirectory)),
			projectDirectory: $projectDirectory,
			autoloadResolver: new ComposerAutoloadResolver($projectDirectory),
			locations: $this->generatorLocations($projectDirectory),
			classNameResolver: new WordPressClassNameResolver(),
			stubResolver: new StubResolver($projectDirectory),
			stubRenderer: new StubRenderer(),
			fileWriter: $this->fileWriter(),
			providerUpdater: $this->providerUpdater(),
			migrationFactory: $this->migrationFactory($root)
		);
	}

	private function migrationFactory(string $root): MigrationFileFactory {
		$projectDirectory = new ProjectDirectory($root);

		return new MigrationFileFactory(
			projectDirectory: $projectDirectory,
			autoloadResolver: new ComposerAutoloadResolver($projectDirectory),
			classNameResolver: new WordPressClassNameResolver(),
			stubResolver: new StubResolver($projectDirectory),
			stubRenderer: new StubRenderer(),
			config: new ArrayConfiguration($this->generatorConfig),
		);
	}

	private function providerCommand(string $root): ProviderCommand {
		$projectDirectory = new ProjectDirectory($root);

		return new ProviderCommand(
			projectDirectory: $projectDirectory,
			autoloadResolver: new ComposerAutoloadResolver($projectDirectory),
			locations: $this->generatorLocations($projectDirectory),
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
