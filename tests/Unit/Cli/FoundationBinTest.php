<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli;

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
		file_put_contents($root . '/foundation/config.php', $config);

		$result = $this->runPhp([
			dirname(__DIR__, 3) . '/src/Cli/bin/foundation',
			'make:wpcli-command',
			'Sync_Products_Command',
			'--no-ansi',
		], $root);

		$this->assertSame(0, $result['status'], $result['stderr'] . $result['stdout']);
		$this->assertFileExists($root . '/src/Commands/Sync_Products_Command.php');
		$this->assertSame('loaded\n', file_get_contents($root . '/foundation/loads'));
	}

	public function test_it_accepts_shared_configuration_for_custom_generators(): void {
		$root = $this->temporaryRoot('foundation-bin-shared-config-');
		mkdir($root . '/foundation');
		file_put_contents($root . '/composer.json', json_encode([
			'autoload' => ['psr-4' => ['Acme\\Plugin\\' => 'src']],
		]));
		$config                         = require dirname(__DIR__, 2) . '/Support/Fixtures/Cli/generator-config.php';
		$config['generators']['report'] = ['namespace' => 'Acme\\Plugin\\Exports'];
		file_put_contents($root . '/foundation/config.php', '<?php return ' . var_export($config, true) . ';');
		$binary = dirname(__DIR__, 3) . '/src/Cli/bin/foundation';

		$list = $this->runPhp([$binary, 'list', '--no-ansi'], $root);
		$this->assertSame(0, $list['status'], $list['stderr']);
		$this->assertStringContainsString('make:wpcli-command', $list['stdout']);

		$result = $this->runPhp([$binary, 'make:wpcli-command', 'Sync_Command', '--no-ansi'], $root);
		$this->assertSame(0, $result['status'], $result['stderr'] . $result['stdout']);
		$this->assertFileExists($root . '/src/Commands/Sync_Command.php');
	}

	public function test_the_custom_generator_example_uses_project_defaults_and_overrides(): void {
		$root    = $this->temporaryRoot('foundation-bin-custom-generator-');
		$fixture = dirname(__DIR__, 2) . '/Support/Fixtures/Cli/custom-generator';
		foreach ([
			'bin/your-plugin',
			'foundation/stubs/report/report.stub',
			'src/Cli/Commands/Make/Report/Report_Command.php',
			'src/Cli/Commands/Make/Report/Report_Provider.php',
		] as $file) {
			$directory = dirname($root . '/' . $file);

			if (! is_dir($directory)) {
				mkdir($directory, 0777, true);
			}
			copy($fixture . '/' . $file, $root . '/' . $file);
		}
		file_put_contents($root . '/composer.json', json_encode([
			'autoload' => ['psr-4' => ['Plugin\\' => 'src']],
		]));
		mkdir($root . '/vendor');
		file_put_contents($root . '/vendor/autoload.php', sprintf(
			<<<'PHP'
			<?php
			$loader = require %s;
			$loader->addPsr4('Plugin\\', dirname(__DIR__) . '/src');
			return $loader;
			PHP,
			var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true)
		));
		$binary = $root . '/bin/your-plugin';
		$list   = $this->runPhp([$binary, 'list', '--no-ansi'], $root);
		$this->assertSame(0, $list['status'], $list['stderr']);
		$this->assertStringContainsString('make:report', $list['stdout']);
		$this->assertStringContainsString('make:database-table', $list['stdout']);

		$result = $this->runPhp([$binary, 'make:report', 'Sales_Report', '--no-ansi'], $root);
		$this->assertSame(0, $result['status'], $result['stderr'] . $result['stdout']);
		$this->assertFileExists($root . '/src/Reports/Sales_Report.php');

		file_put_contents($root . '/foundation/config.php', '<?php return ' . var_export([
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
		file_put_contents($root . '/foundation/config.php', '<?php return "invalid";');

		$result = $this->runPhp([dirname(__DIR__, 3) . '/src/Cli/bin/foundation', 'list'], $root);

		$this->assertSame(1, $result['status']);
		$this->assertStringContainsString('foundation/config.php must return a configuration array.', $result['stderr']);
		$this->assertDirectoryDoesNotExist($root . '/src');
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
