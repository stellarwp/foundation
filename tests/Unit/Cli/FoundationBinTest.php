<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli;

use StellarWP\Foundation\Cli\CliProvider;
use StellarWP\Foundation\Tests\TestCase;

final class FoundationBinTest extends TestCase
{
	private string $tempDir;

	protected function setUp(): void {
		parent::setUp();

		$this->tempDir = $this->prepare_temp_dir('foundation-bin');
	}

	public function test_it_uses_the_composer_bin_proxy_autoload_path(): void {
		$root  = $this->temporaryRoot('foundation-bin-proxy-');
		$proxy = $root . '/proxy.php';

		file_put_contents($proxy, sprintf(
			<<<'PHP'
			<?php declare(strict_types=1);

			chdir(%s);
			$GLOBALS['_composer_autoload_path'] = %s;
			$_SERVER['argv'] = ['foundation', 'list', '--no-ansi'];
			$_SERVER['argc'] = 3;

			require %s;
			PHP,
			var_export($root, true),
			var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true),
			var_export(dirname(__DIR__, 3) . '/src/Cli/bin/foundation', true)
		));

		$result = $this->runPhp([$proxy]);

		$this->assertSame(0, $result['status']);
		$this->assertStringContainsString('make:wpcli-command', $result['stdout']);
		$this->assertStringNotContainsString('package:create', $result['stdout']);
	}

	public function test_it_skips_the_builtin_provider_regardless_of_class_spelling(): void {
		$root = $this->temporaryRoot('foundation-bin-provider-identity-');

		foreach ([CliProvider::class, '\\' . CliProvider::class, strtolower(CliProvider::class)] as $provider) {
			file_put_contents($root . '/config.php', '<?php return ' . var_export([
				'cli' => ['providers' => [$provider]],
			], true) . ';');
			$result = $this->runPhp([dirname(__DIR__, 3) . '/src/Cli/bin/foundation', 'list', '--raw'], $root);

			$this->assertSame(0, $result['status'], $provider . ': ' . $result['stderr']);
			$this->assertSame(1, substr_count($result['stdout'], 'make:database-migration'));
		}
	}

	public function test_it_reports_a_clear_error_when_composer_autoload_cannot_be_found(): void {
		$root = $this->temporaryRoot('foundation-bin-missing-autoload-');

		mkdir($root . '/package/bin', 0777, true);
		file_put_contents($root . '/package/bin/foundation', (string) file_get_contents(dirname(__DIR__, 3) . '/src/Cli/bin/foundation'));

		$result = $this->runPhp([$root . '/package/bin/foundation'], $root);

		$this->assertSame(1, $result['status']);
		$this->assertStringContainsString('Foundation CLI bootstrap failed: Could not find Composer autoload.php.', $result['stderr']);
	}

	public function test_it_loads_project_generator_configuration_once_per_invocation(): void {
		$root = $this->temporaryRoot('foundation-bin-config-');
		mkdir($root . '/foundation');
		file_put_contents($root . '/composer.json', json_encode([
			'autoload' => ['psr-4' => ['Acme\\Plugin\\' => 'src']],
		]));
		$config = (string) file_get_contents(dirname(__DIR__, 2) . '/Support/Fixtures/Cli/generator-config.php');
		$config = str_replace('return [', "file_put_contents(__DIR__ . '/loads', 'loaded\\n', FILE_APPEND);\n\nreturn [", $config);
		file_put_contents($root . '/config.php', $config);

		$result = $this->runPhp([
			dirname(__DIR__, 3) . '/src/Cli/bin/foundation',
			'make:wpcli-command',
			'Sync_Products_Command',
			'--no-ansi',
		], $root);

		$this->assertSame(0, $result['status'], $result['stderr'] . $result['stdout']);
		$this->assertFileExists($root . '/src/Commands/Sync_Products_Command.php');
		$this->assertSame('loaded\n', file_get_contents($root . '/loads'));
	}

	public function test_it_accepts_shared_configuration_for_custom_generators(): void {
		$root = $this->temporaryRoot('foundation-bin-shared-config-');
		mkdir($root . '/foundation');
		file_put_contents($root . '/composer.json', json_encode([
			'autoload' => ['psr-4' => ['Acme\\Plugin\\' => 'src']],
		]));
		$config                         = require dirname(__DIR__, 2) . '/Support/Fixtures/Cli/generator-config.php';
		$config['generators']['report'] = ['namespace' => 'Acme\\Plugin\\Exports'];
		file_put_contents($root . '/config.php', '<?php return ' . var_export($config, true) . ';');
		$binary = dirname(__DIR__, 3) . '/src/Cli/bin/foundation';

		$list = $this->runPhp([$binary, 'list', '--no-ansi'], $root);
		$this->assertSame(0, $list['status'], $list['stderr']);
		$this->assertStringContainsString('make:wpcli-command', $list['stdout']);

		$result = $this->runPhp([$binary, 'make:wpcli-command', 'Sync_Command', '--no-ansi'], $root);
		$this->assertSame(0, $result['status'], $result['stderr'] . $result['stdout']);
		$this->assertFileExists($root . '/src/Commands/Sync_Command.php');
	}

	public function test_the_custom_generator_example_uses_project_defaults_and_overrides(): void {
		$root   = $this->customProject();
		$binary = $root . '/proxy.php';
		$list   = $this->runPhp([$binary, 'list', '--no-ansi'], $root);
		$this->assertSame(0, $list['status'], $list['stderr']);
		$this->assertStringContainsString('make:report', $list['stdout']);
		$this->assertStringContainsString('make:database-table', $list['stdout']);

		$result = $this->runPhp([$binary, 'make:report', 'Sales_Report', '--no-ansi'], $root);
		$this->assertSame(0, $result['status'], $result['stderr'] . $result['stdout']);
		$this->assertFileExists($root . '/src/Reports/Sales_Report.php');

		file_put_contents($root . '/config.php', '<?php return ' . var_export([
			'generators' => ['report' => ['namespace' => 'Plugin\\Exports']],
		], true) . ';');
		$result = $this->runPhp([$binary, 'make:report', 'Sales_Report', '--no-ansi'], $root);
		$this->assertSame(0, $result['status'], $result['stderr'] . $result['stdout']);
		$contents = (string) file_get_contents($root . '/src/Exports/Sales_Report.php');
		$this->assertStringContainsString('namespace Plugin\\Exports;', $contents);

		$repeat = $this->runPhp([$binary, 'make:report', 'Sales_Report', '--no-ansi'], $root);
		$this->assertSame(1, $repeat['status']);
		$this->assertSame($contents, file_get_contents($root . '/src/Exports/Sales_Report.php'));

		$result = $this->runPhp([
			$binary, 'make:report', 'Sales_Report', '--namespace=Plugin\\Reports\\Sales', '--path=custom/reports', '--no-ansi',
		], $root);
		$this->assertSame(0, $result['status'], $result['stderr'] . $result['stdout']);
		$this->assertStringContainsString('namespace Plugin\\Reports\\Sales;', (string) file_get_contents($root . '/custom/reports/Sales_Report.php'));
	}

	public function test_it_reports_configuration_files_that_do_not_return_arrays(): void {
		$root = $this->temporaryRoot('foundation-bin-invalid-config-');
		mkdir($root . '/foundation');
		file_put_contents($root . '/config.php', '<?php return "invalid";');

		$result = $this->runPhp([dirname(__DIR__, 3) . '/src/Cli/bin/foundation', 'list'], $root);

		$this->assertSame(1, $result['status']);
		$this->assertStringContainsString('config.php must return a configuration array.', $result['stderr']);
		$this->assertDirectoryDoesNotExist($root . '/src');
	}

	public function test_it_resolves_multiple_commands_and_their_configured_dependencies(): void {
		$root = $this->customProject();
		file_put_contents($root . '/config.php', '<?php return ' . var_export([
			'foundation' => ['prefix' => 'consumer-app'],
			'cli'        => [
				'providers'             => ['Plugin\\Dev\\Configured_Provider', 'Plugin\\Dev\\Configured_Provider'],
				'tooling_provider_path' => 'dev/Configured_Provider.php',
			],
		], true) . ';');
		$result = $this->runPhp([$root . '/proxy.php', 'tooling:prefix'], $root);
		$this->assertSame(0, $result['status'], $result['stderr']);
		$this->assertSame("consumer-app\n", $result['stdout']);
	}

	public function test_a_project_command_can_lint_generated_files_with_the_default_process_runner(): void {
		$root = $this->customProject();
		file_put_contents($root . '/config.php', '<?php return ["cli" => ["tooling_provider_path" => "dev/Configured_Provider.php"]];');

		$generated = $this->runPhp([$root . '/proxy.php', 'make:report', 'Sales_Report'], $root);
		$this->assertSame(0, $generated['status'], $generated['stderr'] . $generated['stdout']);

		$result = $this->runPhp([$root . '/proxy.php', 'tooling:lint', 'src/Reports/Sales_Report.php'], $root);
		$this->assertSame(0, $result['status'], $result['stderr'] . $result['stdout']);
		$this->assertStringContainsString('No syntax errors detected', $result['stdout']);

		file_put_contents($root . '/invalid.php', '<?php function');
		$result = $this->runPhp([$root . '/proxy.php', 'tooling:lint', 'invalid.php'], $root);
		$this->assertNotSame(0, $result['status']);
		$this->assertStringContainsString('Errors parsing invalid.php', $result['stdout']);
	}

	public function test_it_finds_the_conventional_provider_through_development_autoloading(): void {
		$root = $this->customProject();
		rename($root . '/src/Tooling', $root . '/dev/Tooling');
		file_put_contents($root . '/composer.json', json_encode([
			'autoload'     => ['psr-4' => ['Plugin\\' => 'src']],
			'autoload-dev' => ['psr-4' => ['Plugin\\Tooling\\' => 'dev/Tooling']],
		]));
		$result = $this->runPhp([$root . '/proxy.php', 'make:report', 'Runtime_Report'], $root);
		$this->assertSame(0, $result['status'], $result['stderr']);
		$this->assertFileExists($root . '/src/Reports/Runtime_Report.php');
	}

	public function test_it_selects_relative_and_absolute_provider_paths_instead_of_the_conventional_provider(): void {
		$root     = $this->customProject();
		$path     = $root . '/dev/Custom_Provider.php';
		$provider = (string) file_get_contents($root . '/dev/Configured_Provider.php');
		file_put_contents($path, str_replace('class Configured_Provider', 'class Custom_Provider', $provider));
		// The conventional file would fail if the override accidentally fell back.
		file_put_contents($root . '/src/Tooling/Tooling_Provider.php', '<?php throw new \\RuntimeException("Unexpected conventional provider");');
		file_put_contents($root . '/composer.json', json_encode([
			'autoload'     => ['psr-4' => ['Plugin\\' => 'src']],
			'autoload-dev' => ['psr-4' => ['Plugin\\Dev\\' => 'dev']],
		]));
		foreach (['dev/Custom_Provider.php', $path] as $selected) {
			file_put_contents($root . '/config.php', '<?php return ' . var_export([
				'cli' => ['tooling_provider_path' => $selected],
			], true) . ';');
			$result = $this->runPhp([$root . '/proxy.php', 'tooling:prefix'], $root);
			$this->assertSame(0, $result['status'], $result['stderr']);
			$this->assertSame("nx\n", $result['stdout']);
		}
	}

	public function test_it_reports_an_explicit_missing_provider_without_falling_back(): void {
		$root = $this->customProject();
		file_put_contents($root . '/config.php', '<?php return ["cli" => ["tooling_provider_path" => "missing.php"]];');
		$result = $this->runPhp([$root . '/proxy.php', 'list'], $root);
		$this->assertSame(1, $result['status']);
		$this->assertStringContainsString('cli.tooling_provider_path', $result['stderr']);
		$this->assertStringContainsString('missing.php', $result['stderr']);
	}

	public function test_shared_configuration_can_load_without_development_classes(): void {
		$root = $this->temporaryRoot('foundation-bin-runtime-');
		file_put_contents($root . '/config.php', '<?php return ["cli" => ["providers" => [\\Plugin\\Tooling\\Tooling_Provider::class]]];');
		$result = $this->runPhp(['-r', 'require "config.php"; echo class_exists("Plugin\\\\Tooling\\\\Tooling_Provider", false) ? "loaded" : "not loaded";'], $root);
		$this->assertSame(0, $result['status'], $result['stderr']);
		$this->assertSame('not loaded', $result['stdout']);
	}

	public function test_the_monorepo_enables_its_maintenance_provider(): void {
		$result = $this->runPhp([dirname(__DIR__, 3) . '/dev/bin/foundation', 'list', '--raw'], dirname(__DIR__, 3));
		$this->assertSame(0, $result['status'], $result['stderr']);
		$this->assertStringContainsString('package:create', $result['stdout']);
	}

	public function test_monorepo_maintenance_uses_its_own_code_without_loading_project_configuration(): void {
		$root = $this->temporaryRoot('monorepo-tooling-');
		file_put_contents($root . '/config.php', '<?php throw new RuntimeException("Project configuration must not run");');
		$result = $this->runPhp([dirname(__DIR__, 3) . '/dev/bin/foundation', 'package:create', 'Log', '--no-interaction'], $root);
		$this->assertSame(0, $result['status'], $result['stderr'] . $result['stdout']);
		$this->assertStringContainsString('stellarwp/foundation-log', $result['stdout']);
	}

	private function customProject(): string {
		$root    = $this->temporaryRoot('foundation-bin-custom-generator-');
		$fixture = dirname(__DIR__, 2) . '/Support/Fixtures/Cli/custom-generator';
		foreach ([
			'foundation/stubs/report/report.stub',
			'src/Tooling/Commands/Report_Command.php',
			'src/Tooling/Commands/Lint_Command.php',
			'src/Tooling/Commands/Prefix_Command.php',
			'dev/Configured_Provider.php',
			'src/Tooling/Tooling_Provider.php',
		] as $file) {
			$directory = dirname($root . '/' . $file);

			if (! is_dir($directory)) {
				mkdir($directory, 0777, true);
			}
			copy($fixture . '/' . $file, $root . '/' . $file);
		}
		file_put_contents($root . '/composer.json', json_encode([
			'autoload'     => ['psr-4' => ['Plugin\\' => 'src']],
			'autoload-dev' => ['psr-4' => ['Plugin\\Dev\\' => 'dev']],
		]));
		mkdir($root . '/vendor');
		file_put_contents($root . '/vendor/autoload.php', sprintf(
			<<<'PHP'
			<?php
			$loader = require %s;
			$composer = json_decode(file_get_contents(dirname(__DIR__) . '/composer.json'), true);
			foreach (['autoload', 'autoload-dev'] as $section) {
				foreach ($composer[$section]['psr-4'] ?? [] as $namespace => $paths) {
					$loader->addPsr4($namespace, array_map(static fn ($path) => dirname(__DIR__) . '/' . $path, (array) $paths), true);
				}
			}
			return $loader;
			PHP,
			var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true)
		));
		$binary = $root . '/proxy.php';
		file_put_contents($binary, '<?php $GLOBALS["_composer_autoload_path"] = __DIR__ . "/vendor/autoload.php"; require ' . var_export(dirname(__DIR__, 3) . '/src/Cli/bin/foundation', true) . ';');

		return $root;
	}

	/**
	 * @param list<string> $arguments
	 *
	 * @return array{status: int, stdout: string, stderr: string}
	 */
	private function runPhp(array $arguments, ?string $cwd = null): array {
		$pipes   = [];
		$process = proc_open(
			array_merge([PHP_BINARY], $arguments),
			[
				1 => ['pipe', 'w'],
				2 => ['pipe', 'w'],
			],
			$pipes,
			$cwd
		);

		if (! is_resource($process)) {
			$this->fail('Could not start PHP subprocess.');
		}

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);

		fclose($pipes[1]);
		fclose($pipes[2]);

		return [
			'status' => proc_close($process),
			'stdout' => is_string($stdout) ? $stdout : '',
			'stderr' => is_string($stderr) ? $stderr : '',
		];
	}

	private function temporaryRoot(string $prefix): string {
		$root = $this->tempDir . '/' . $prefix . bin2hex(random_bytes(8));

		if (! mkdir($root, 0777, true) && ! is_dir($root)) {
			$this->fail(sprintf('Could not create temporary root "%s".', $root));
		}

		return $root;
	}
}
