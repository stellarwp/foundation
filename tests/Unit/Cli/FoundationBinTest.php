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
