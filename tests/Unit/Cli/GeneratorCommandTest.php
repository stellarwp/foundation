<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli;

use Plugin\Tooling\Commands\Report_Command;
use RuntimeException;
use StellarWP\Foundation\Cli\CliProvider;
use StellarWP\Foundation\Cli\Generation\ClassGenerator;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use StellarWP\Foundation\Cli\GeneratorCommand;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GeneratorCommandTest extends TestCase
{
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = $this->prepare_temp_dir('generator-command');
		$fixture    = dirname(__DIR__, 2) . '/Support/Fixtures/Cli/custom-generator';
		require_once $fixture . '/src/Tooling/Commands/Report_Command.php';
		mkdir($this->root . '/foundation/stubs/report', 0777, true);
		copy($fixture . '/foundation/stubs/report/report.stub', $this->root . '/foundation/stubs/report/report.stub');
		file_put_contents($this->root . '/composer.json', json_encode([
			'autoload'     => ['psr-4' => ['Plugin\\' => 'src']],
			'autoload-dev' => ['psr-4' => ['Plugin\\Tooling\\' => 'dev']],
		]));
	}

	public function test_it_generates_a_class_using_command_owned_defaults_without_repeating_construction(): void {
		$command = $this->command();
		$this->assertSame(0, $command->execute(['name' => 'Sales_Report']), $command->getDisplay());
		$this->assertStringContainsString('namespace Plugin\\Reports;', (string) file_get_contents($this->root . '/src/Reports/Sales_Report.php'));
		$this->assertStringContainsString('Created:', $command->getDisplay());
	}

	public function test_configuration_and_explicit_options_override_defaults_and_existing_files_survive(): void {
		$command = $this->command(['generators' => ['report' => ['namespace' => 'Plugin\\Exports']]]);
		$this->assertSame(0, $command->execute(['name' => 'Sales_Report']));
		$path     = $this->root . '/src/Exports/Sales_Report.php';
		$contents = file_get_contents($path);
		$this->assertSame(1, $command->execute(['name' => 'Sales_Report']));
		$this->assertSame($contents, file_get_contents($path));
		$this->assertSame(0, $command->execute(['name' => 'Sales_Report', '--namespace' => 'Custom\\Report', '--path' => 'output']));
		$this->assertStringContainsString('namespace Custom\\Report;', (string) file_get_contents($this->root . '/output/Sales_Report.php'));
	}

	public function test_it_reports_missing_stubs_without_creating_output(): void {
		unlink($this->root . '/foundation/stubs/report/report.stub');
		$command = $this->command();
		$this->assertSame(1, $command->execute(['name' => 'Sales_Report']));
		$this->assertStringContainsString('Could not read stub', $command->getDisplay());
		$this->assertDirectoryDoesNotExist($this->root . '/src');
	}

	public function test_it_reports_invalid_class_names_without_creating_output(): void {
		$command = $this->command();
		$this->assertSame(1, $command->execute(['name' => '123']));
		$this->assertDirectoryDoesNotExist($this->root . '/src');
	}

	public function test_it_reports_an_incomplete_generator_definition(): void {
		$container = (new ContainerFactory())->create(new ArrayConfiguration());
		$container->register(CliProvider::class);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('must define NAME and CONFIG_KEY');
		new class($container->get(ClassGenerator::class)) extends GeneratorCommand {
			protected function stub(): string {
				return 'unused.stub';
			}
		};
	}

	public function test_it_accepts_an_absolute_stub_path(): void {
		$container = (new ContainerFactory())->create(new ArrayConfiguration());
		$container->register(CliProvider::class);
		$container->singleton(ProjectDirectory::class, new ProjectDirectory($this->root));
		$file = $container->get(ClassGenerator::class)->generate(
			'Absolute_Report', Report_Command::CONFIG_KEY, Report_Command::DEFAULT_NAMESPACE,
			$this->root . '/foundation/stubs/report/report.stub'
		);
		$this->assertFileExists($file->path);
		$this->assertStringContainsString('namespace Plugin\\Reports;', $file->contents);
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function command(array $config = []): CommandTester {
		$container = (new ContainerFactory())->create(new ArrayConfiguration($config));
		$container->register(CliProvider::class);
		$container->singleton(ProjectDirectory::class, new ProjectDirectory($this->root));

		return new CommandTester($container->get(Report_Command::class));
	}
}
