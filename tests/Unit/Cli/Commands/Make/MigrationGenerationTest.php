<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Commands\Make;

use StellarWP\Foundation\Cli\CliProvider;
use StellarWP\Foundation\Cli\Commands\Make\Database\MigrationCommand;
use StellarWP\Foundation\Cli\Commands\Make\Database\TableCommand;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MigrationGenerationTest extends TestCase
{
	private string $root;
	private Container $services;

	protected function setUp(): void {
		parent::setUp();
		$this->root = $this->prepare_temp_dir('migration-generation');
		$this->configure();
	}

	/** @param array<string, mixed> $configuration */
	private function configure(array $configuration = [], string $namespace = 'Example\\', string $path = 'src/'): void {
		file_put_contents($this->root . '/composer.json', json_encode([
			'autoload' => ['psr-4' => [$namespace => $path]],
			'require'  => ['stellarwp/foundation-migrations' => '^2.0'],
		], JSON_THROW_ON_ERROR));
		$this->services = (new ContainerFactory())->create(new ArrayConfiguration($configuration));
		$this->services->register(CliProvider::class);
		$this->services->singleton(ProjectDirectory::class, new ProjectDirectory($this->root));
	}

	private function migration(): CommandTester {
		return new CommandTester($this->services->get(MigrationCommand::class));
	}

	private function table(): CommandTester {
		return new CommandTester($this->services->get(TableCommand::class));
	}

	/** @return list<string> */
	private function files(string $directory = 'db/migrations'): array {
		return glob($this->root . '/' . $directory . '/[0-9]*.php') ?: [];
	}

	public function test_combined_creation_then_alteration_has_ordered_file_identities_without_provider_edits(): void {
		$table = $this->table();
		$this->assertSame(0, $table->execute(['name' => 'reports', '--migration' => true]), $table->getDisplay());
		$this->assertStringNotContainsString('Runtime dependency missing', $table->getDisplay());
		$createFile = $this->files()[0];
		$create     = (string) file_get_contents($createFile);
		$this->assertMatchesRegularExpression('/[0-9]{14}_create_reports_table.php$/', $createFile);
		$this->assertStringContainsString('extends Migration', $create);
		$this->assertStringContainsString('return new class extends Migration', $create);
		$this->assertStringNotContainsString('Reports_Table', $create);
		$this->assertStringContainsString("\$schema->create( 'reports' )", $create);
		$this->assertStringContainsString("\$schema->drop( 'reports' )", $create);
		$this->assertStringNotContainsString('const string ID', $create);
		$this->assertStringNotContainsString('function id()', $create);
		$this->assertFileDoesNotExist($this->root . '/src/Database/Provider.php');

		$command = $this->migration();
		$this->assertSame(0, $command->execute(['name' => 'Add_Published_At', '--table' => 'reports']), $command->getDisplay());
		$this->assertStringNotContainsString('Runtime dependency missing', $command->getDisplay());
		$files = $this->files();
		$this->assertCount(2, $files);
		$this->assertSame($createFile, $files[0]);
		$alter = (string) file_get_contents($files[1]);
		$this->assertStringContainsString("\$schema->table( 'reports' )", $alter);
		$this->assertStringContainsString('throw new IrreversibleMigration', $alter);
		$this->assertStringNotContainsString('$schema->drop', $alter);
		$this->assertSame($create, file_get_contents($createFile));
	}

	public function test_combined_generation_warns_when_migrations_are_only_installed_for_development(): void {
		$manifest                = json_decode((string) file_get_contents($this->root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
		$manifest['require']     = ['stellarwp/foundation-database' => '^2.0'];
		$manifest['require-dev'] = ['stellarwp/foundation-migrations' => '^2.0'];
		file_put_contents($this->root . '/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
		$command = $this->table();

		$this->assertSame(0, $command->execute(['name' => 'reports', '--migration' => true]));
		$this->assertStringContainsString('Move stellarwp/foundation-migrations or stellarwp/foundation to require', $command->getDisplay());
	}

	public function test_generation_creates_custom_directories_without_composer_namespace_mappings(): void {
		$this->configure(['migrations' => ['path' => 'migrations']]);
		$command = $this->migration();
		$this->assertSame(0, $command->execute(['name' => 'Reports/Add_Status']), $command->getDisplay());
		$file = $this->files('migrations/reports')[0];
		$this->assertStringNotContainsString('namespace ', (string) file_get_contents($file));
		$this->assertDirectoryDoesNotExist($this->root . '/src');
	}

	public function test_absolute_location_and_foundation_root_use_the_same_configuration(): void {
		$this->configure(['foundation' => ['root' => $this->root], 'migrations' => ['path' => $this->root . '/src/History']]);
		$command = $this->migration();
		$this->assertSame(0, $command->execute(['name' => 'Rebuild']), $command->getDisplay());
		$this->assertCount(1, $this->files('src/History'));
	}

	public function test_new_timestamps_follow_migrations_in_other_feature_folders(): void {
		mkdir($this->root . '/db/migrations/Reports', 0777, true);
		file_put_contents($this->root . '/db/migrations/Reports/20990101000000_create.php', '<?php');
		file_put_contents($this->root . '/db/migrations/Reports/README.md', 'History');
		$command = $this->migration();
		$this->assertSame(0, $command->execute(['name' => 'Add_Status']));
		$this->assertStringEndsWith('20990101000001_add_status.php', $this->files()[0]);
	}

	public function test_repeated_descriptions_create_new_files_and_never_overwrite_history(): void {
		$command  = $this->migration();
		$exitCode = $command->execute(['name' => 'Change_Status']);
		$this->assertSame(0, $exitCode);
		$file     = $this->files()[0];
		$contents = file_get_contents($file);
		$exitCode = $command->execute(['name' => 'Change_Status']);
		$this->assertSame(0, $exitCode);
		$this->assertCount(2, $this->files());
		$this->assertSame($contents, file_get_contents($file));
		$this->assertFalse($this->services->get(MigrationCommand::class)->getDefinition()->hasOption('force'));
	}

	public function test_standalone_create_and_alter_use_table_names_without_application_classes(): void {
		file_put_contents($this->root . '/composer.json', '{"require":{"stellarwp/foundation-migrations":"^2.0"}}');
		$command = $this->migration();
		$this->assertSame(0, $command->execute(['name' => 'create_reports', '--create' => 'your_plugin_reports']), $command->getDisplay());
		$this->assertSame(0, $command->execute(['name' => 'add_status', '--table' => 'your_plugin_reports']), $command->getDisplay());
		$this->assertCount(2, $this->files());
		$this->assertDirectoryDoesNotExist($this->root . '/src');
	}

	public function test_combined_generation_preserves_an_explicit_physical_table_suffix(): void {
		$this->assertSame(0, $this->table()->execute(['name' => 'reports', '--table-name' => 'your_plugin_reports', '--migration' => true]));
		$table     = (string) file_get_contents($this->root . '/src/Database/Tables/Reports_Table.php');
		$migration = (string) file_get_contents($this->files()[0]);
		$this->assertStringContainsString("UNPREFIXED_TABLE_NAME = 'your_plugin_reports';", $table);
		$this->assertStringContainsString("\$schema->create( 'your_plugin_reports' )", $migration);
	}

	public function test_generic_migration_does_not_infer_table_ownership_from_its_name(): void {
		$command = $this->migration();
		$this->assertSame(0, $command->execute(['name' => 'Create_Reports_Table']));
		$contents = (string) file_get_contents($this->files()[0]);
		$this->assertStringNotContainsString('$schema->create', $contents);
		$this->assertStringNotContainsString('$schema->drop', $contents);
		$this->assertStringContainsString('IrreversibleMigration', $contents);
	}

	public function test_invalid_options_and_locations_fail_without_writing(): void {
		foreach ([
			['name' => 'Change', '--table' => 'reports', '--create' => 'reports'],
			['name' => 'Change', '--table' => ''],
			['name' => 'Change', '--create' => ''],
			['name' => 'Change', '--table' => 'Tables/Reports'],
			['name' => 'Change', '--table' => 'reports; DROP TABLE reports'],
			['name' => 'Change', '--table' => 'Example\\Tables\\1Invalid'],
			['name' => 'Change', '--table' => 'Missing\\Reports'],
			['name' => '../Change'],
			['name' => '/Change'],
			['name' => str_repeat('a', 180)],
		] as $input) {
			$this->assertSame(1, $this->migration()->execute($input));
			$this->assertSame([], $this->files());
		}

		$this->configure(['migrations' => ['path' => '']]);
		$command = $this->migration();
		$this->assertSame(1, $command->execute(['name' => 'Change']));
		$this->assertStringContainsString('cannot be blank', $command->getDisplay());
		$this->assertDirectoryDoesNotExist($this->root . '/unmapped');
	}

	public function test_combined_generation_writes_neither_file_if_migration_location_is_invalid(): void {
		$this->configure(['migrations' => ['path' => '']]);
		$this->assertSame(1, $this->table()->execute(['name' => 'reports', '--migration' => true]));
		$this->assertFileDoesNotExist($this->root . '/src/Database/Tables/Reports_Table.php');
	}
	public function test_strauss_imports_apply_to_every_generated_migration_kind(): void {
		$manifest                                         = json_decode((string) file_get_contents($this->root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
		$manifest['extra']['strauss']['namespace_prefix'] = 'Scoped\\';
		file_put_contents($this->root . '/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
		$this->assertSame(0, $this->table()->execute(['name' => 'reports', '--migration' => true]));
		$this->assertSame(0, $this->migration()->execute(['name' => 'Add_Status', '--table' => 'reports']));
		$this->assertSame(0, $this->migration()->execute(['name' => 'Backfill']));
		$this->assertCount(3, $this->files());

		foreach ($this->files() as $file) {
			$contents = (string) file_get_contents($file);
			$this->assertStringContainsString('use Scoped\\StellarWP\\Foundation\\Migrations\\Migration;', $contents);
			$this->assertStringContainsString('use Scoped\\StellarWP\\Foundation\\Migrations\\Schema\\Blueprint;', $contents);
		}
	}

	public function test_each_migration_mode_uses_the_project_stub_override(): void {
		mkdir($this->root . '/foundation/stubs/database', 0777, true);

		foreach (['create-table-migration', 'alter-table-migration', 'migration'] as $stub) {
			file_put_contents($this->root . '/foundation/stubs/database/' . $stub . '.stub', '<?php // custom ' . $stub . "\n" . 'return new class extends \\{{ foundation_database_migration }} { public function up( \\{{ foundation_database_schema }} $schema ): void {} };');
		}

		$this->assertSame(0, $this->table()->execute(['name' => 'reports', '--migration' => true]));
		$this->assertSame(0, $this->migration()->execute(['name' => 'Add_Status', '--table' => 'reports']));
		$this->assertSame(0, $this->migration()->execute(['name' => 'Backfill']));
		$files = $this->files();
		$this->assertCount(3, $files);

		foreach (['create-table-migration', 'alter-table-migration', 'migration'] as $i => $stub) {
			$this->assertStringContainsString('// custom ' . $stub, (string) file_get_contents($files[$i]));
		}
	}
}
