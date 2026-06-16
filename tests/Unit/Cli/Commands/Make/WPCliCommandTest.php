<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Commands\Make;

use StellarWP\Foundation\Cli\Commands\Make\WPCliCommand;
use StellarWP\Foundation\Cli\Generation\ClassNameResolver;
use StellarWP\Foundation\Cli\Generation\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
use StellarWP\Foundation\Cli\Generation\StubRenderer;
use StellarWP\Foundation\Cli\Generation\StubResolver;
use StellarWP\Foundation\Tests\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WPCliCommandTest extends TestCase
{
	/**
	 * @var list<string>
	 */
	private array $temporaryRoots = [];

	protected function tearDown(): void {
		foreach ($this->temporaryRoots as $temporaryRoot) {
			$this->removeDirectory($temporaryRoot);
		}

		parent::tearDown();
	}

	public function test_it_generates_a_wpcli_command_from_project_autoload_defaults(): void {
		$root    = $this->temporaryProject();
		$command = $this->command($root);
		$tester  = new CommandTester($command);

		$statusCode = $tester->execute([
			'name' => 'sync-products',
		]);

		$path = $root . '/src/Cli/Commands/Sync_Products_Command.php';

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertFileExists($path);
		$this->assertStringContainsString('Created: src/Cli/Commands/Sync_Products_Command.php', $tester->getDisplay());

		$contents = (string) file_get_contents($path);

		$this->assertStringContainsString('namespace Acme\\Plugin\\Cli\\Commands;', $contents);
		$this->assertStringContainsString('use StellarWP\\Foundation\\WPCli\\Command;', $contents);
		$this->assertStringContainsString('final class Sync_Products_Command extends Command {', $contents);
		$this->assertStringContainsString('public const string ARG_ITEM', $contents);
		$this->assertStringContainsString('public function runCommand( array $args = [], array $assocArgs = [] ): int {', $contents);
		$this->assertStringContainsString('WP_CLI::line( sprintf(', $contents);
		$this->assertStringContainsString("return 'sync-products';", $contents);
		$this->assertStringContainsString("return 'Sync products.';", $contents);
	}

	public function test_it_accepts_generation_options(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->command($root));

		$statusCode = $tester->execute([
			'name'          => 'Import_Customers',
			'--namespace'   => 'Acme\\Plugin\\Admin\\Cli',
			'--path'        => 'custom/commands',
			'--subcommand'  => 'customers:import',
			'--description' => 'Import customers.',
		]);

		$contents = (string) file_get_contents($root . '/custom/commands/Import_Customers_Command.php');

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('namespace Acme\\Plugin\\Admin\\Cli;', $contents);
		$this->assertStringContainsString("return 'customers:import';", $contents);
		$this->assertStringContainsString("return 'Import customers.';", $contents);
	}

	public function test_it_escapes_php_string_values_in_generated_code(): void {
		$root        = $this->temporaryProject();
		$tester      = new CommandTester($this->command($root));
		$description = "Don't sync \\ products.";
		$subcommand  = "products:don't-sync";

		$statusCode = $tester->execute([
			'name'          => 'Sync_Products',
			'--description' => $description,
			'--subcommand'  => $subcommand,
		]);

		$contents = (string) file_get_contents($root . '/src/Cli/Commands/Sync_Products_Command.php');

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('return ' . var_export($subcommand, true) . ';', $contents);
		$this->assertStringContainsString('return ' . var_export($description, true) . ';', $contents);
	}

	public function test_it_reports_malformed_composer_json_errors(): void {
		$root = $this->temporaryRoot('foundation-make-wpcli-malformed-composer-');

		file_put_contents($root . '/composer.json', '{"autoload":');

		$tester     = new CommandTester($this->command($root));
		$statusCode = $tester->execute([
			'name' => 'Sync_Products',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('Could not parse composer.json', $tester->getDisplay());
	}

	public function test_it_uses_strauss_namespace_prefix_for_foundation_imports(): void {
		$root = $this->temporaryProject([
			'extra' => [
				'strauss' => [
					'namespace_prefix' => 'Acme\\Product\\',
				],
			],
		]);
		$tester = new CommandTester($this->command($root));

		$statusCode = $tester->execute([
			'name' => 'Sync_Products',
		]);

		$contents = (string) file_get_contents($root . '/src/Cli/Commands/Sync_Products_Command.php');

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('use Acme\\Product\\StellarWP\\Foundation\\WPCli\\Command;', $contents);
		$this->assertStringNotContainsString('use StellarWP\\Foundation\\WPCli\\Command;', $contents);
	}

	public function test_it_uses_unprefixed_foundation_imports_when_strauss_namespace_prefix_is_blank(): void {
		$root = $this->temporaryProject([
			'extra' => [
				'strauss' => [
					'namespace_prefix' => '',
				],
			],
		]);
		$tester = new CommandTester($this->command($root));

		$statusCode = $tester->execute([
			'name' => 'Sync_Products',
		]);

		$contents = (string) file_get_contents($root . '/src/Cli/Commands/Sync_Products_Command.php');

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('use StellarWP\\Foundation\\WPCli\\Command;', $contents);
	}

	public function test_it_accepts_an_absolute_output_path(): void {
		$root       = $this->temporaryProject();
		$outputRoot = $this->temporaryRoot('foundation-make-wpcli-output-');
		$tester     = new CommandTester($this->command($root));

		$statusCode = $tester->execute([
			'name'   => 'Export_Customers',
			'--path' => $outputRoot,
		]);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertFileExists($outputRoot . '/Export_Customers_Command.php');
		$this->assertStringContainsString('Created: ' . $outputRoot . '/Export_Customers_Command.php', $tester->getDisplay());
	}

	public function test_it_rejects_namespace_without_path_when_namespace_is_outside_the_autoload_root(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->command($root));

		$statusCode = $tester->execute([
			'name'        => 'Sync_Products',
			'--namespace' => 'Acme\\PluginTools\\Cli',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('Namespace "Acme\\PluginTools\\Cli" is outside Composer PSR-4 namespace "Acme\\Plugin\\".', $tester->getDisplay());
		$this->assertFileDoesNotExist($root . '/src/Tools/Cli/Sync_Products_Command.php');
	}

	public function test_it_accepts_namespace_without_path_when_namespace_matches_the_autoload_root_boundary(): void {
		$root   = $this->temporaryProject();
		$tester = new CommandTester($this->command($root));

		$statusCode = $tester->execute([
			'name'        => 'Sync_Products',
			'--namespace' => 'Acme\\Plugin\\Admin\\Cli',
		]);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertFileExists($root . '/src/Admin/Cli/Sync_Products_Command.php');
	}

	public function test_it_reports_autoload_resolution_errors(): void {
		$root = $this->temporaryRoot('foundation-make-wpcli-invalid-');

		file_put_contents($root . '/composer.json', json_encode(['autoload' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

		$tester     = new CommandTester($this->command($root));
		$statusCode = $tester->execute([
			'name' => 'Sync_Products',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('Could not find an autoload.psr-4 namespace in composer.json.', $tester->getDisplay());
	}

	public function test_it_uses_project_stub_overrides(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/foundation/stubs/wpcli', 0777, true);
		file_put_contents($root . '/foundation/stubs/wpcli/command.stub', 'Generated {{ class }} in {{ namespace }}');

		$tester = new CommandTester($this->command($root));
		$tester->execute([
			'name' => 'Sync_Products',
		]);

		$this->assertSame(
			'Generated Sync_Products_Command in Acme\\Plugin\\Cli\\Commands',
			(string) file_get_contents($root . '/src/Cli/Commands/Sync_Products_Command.php')
		);
	}

	public function test_it_refuses_to_overwrite_existing_files_without_force(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Cli/Commands', 0777, true);
		file_put_contents($root . '/src/Cli/Commands/Sync_Products_Command.php', 'existing');

		$tester     = new CommandTester($this->command($root));
		$statusCode = $tester->execute([
			'name' => 'Sync_Products',
		]);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('File already exists: src/Cli/Commands/Sync_Products_Command.php', $tester->getDisplay());
		$this->assertSame('existing', (string) file_get_contents($root . '/src/Cli/Commands/Sync_Products_Command.php'));
	}

	public function test_it_overwrites_existing_files_with_force(): void {
		$root = $this->temporaryProject();

		mkdir($root . '/src/Cli/Commands', 0777, true);
		file_put_contents($root . '/src/Cli/Commands/Sync_Products_Command.php', 'existing');

		$tester     = new CommandTester($this->command($root));
		$statusCode = $tester->execute([
			'name'    => 'Sync_Products',
			'--force' => true,
		]);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('final class Sync_Products_Command extends Command {', (string) file_get_contents($root . '/src/Cli/Commands/Sync_Products_Command.php'));
	}

	private function command(string $root): WPCliCommand {
		return new WPCliCommand(
			rootPath: $root,
			autoloadResolver: new ComposerAutoloadResolver($root),
			classNameResolver: new ClassNameResolver(),
			stubResolver: new StubResolver($root),
			stubRenderer: new StubRenderer(),
			fileWriter: new GeneratedFileWriter()
		);
	}

	/**
	 * @param array<string,mixed> $composer
	 */
	private function temporaryProject(array $composer = []): string {
		$root = $this->temporaryRoot('foundation-make-wpcli-test-');

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
		$root = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));

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
