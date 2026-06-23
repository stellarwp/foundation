<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Commands\Package;

use RuntimeException;
use StellarWP\Foundation\Cli\Commands\Package\PackageScaffolder;
use StellarWP\Foundation\Tests\TestCase;

final class PackageScaffolderTest extends TestCase
{
	/**
	 * @var list<string>
	 */
	private array $temporaryRoots = [];

	private string $tempDir;

	protected function setUp(): void {
		parent::setUp();

		$this->tempDir = $this->prepare_temp_dir('package-scaffolder');
	}

	protected function tearDown(): void {
		foreach ($this->temporaryRoots as $temporaryRoot) {
			$this->removeDirectory($temporaryRoot);
		}

		parent::tearDown();
	}

	public function test_it_creates_a_package_scaffold_with_the_default_package_name(): void {
		$rootPath   = $this->temporaryRoot();
		$scaffolder = new PackageScaffolder($rootPath);

		$scaffold = $scaffolder->create('WPCli', $scaffolder->defaultPackageName('WPCli'));

		$this->assertSame('stellarwp/foundation-wpcli', $scaffold->package->name);
		$this->assertSame('WPCli', $scaffold->package->component);
		$this->assertSame('src/WPCli', $scaffold->package->directory);
		$this->assertSame([
			'composer.json',
			'README.md',
			'.gitattributes',
			'.gitignore',
			'.github/workflows/close-pull-request.yml',
		], $scaffold->createdFiles);
		$this->assertFileExists($rootPath . '/src/WPCli/composer.json');

		$composer = $this->packageComposer($rootPath, 'WPCli');

		$this->assertArrayHasKey('StellarWP\\Foundation\\WPCli\\', $composer['autoload']['psr-4']);
		$this->assertSame('1.0.x-dev', $composer['extra']['branch-alias']['dev-main']);
	}

	public function test_it_uses_the_existing_split_package_branch_alias(): void {
		$rootPath = $this->temporaryRoot();

		$this->writePackageComposer($rootPath, 'Container', '1.2.x-dev');

		(new PackageScaffolder($rootPath))->create('WPCli', 'stellarwp/foundation-wpcli');

		$composer = $this->packageComposer($rootPath, 'WPCli');

		$this->assertSame('1.2.x-dev', $composer['extra']['branch-alias']['dev-main']);
	}

	public function test_it_uses_the_highest_existing_split_package_branch_alias(): void {
		$rootPath = $this->temporaryRoot();

		$this->writePackageComposer($rootPath, 'Container', '1.1.x-dev');
		$this->writePackageComposer($rootPath, 'Log', '1.2.x-dev');

		(new PackageScaffolder($rootPath))->create('WPCli', 'stellarwp/foundation-wpcli');

		$composer = $this->packageComposer($rootPath, 'WPCli');

		$this->assertSame('1.2.x-dev', $composer['extra']['branch-alias']['dev-main']);
	}

	public function test_it_ignores_invalid_existing_split_package_branch_aliases(): void {
		$rootPath = $this->temporaryRoot();

		$this->writePackageComposer($rootPath, 'Container', '1.x-dev');
		$this->writePackageComposer($rootPath, 'Log', '1.2.x-dev');
		$this->writeMalformedPackageComposer($rootPath, 'Pipeline');

		(new PackageScaffolder($rootPath))->create('WPCli', 'stellarwp/foundation-wpcli');

		$composer = $this->packageComposer($rootPath, 'WPCli');

		$this->assertSame('1.2.x-dev', $composer['extra']['branch-alias']['dev-main']);
	}

	public function test_it_accepts_a_custom_foundation_package_name(): void {
		$scaffold = (new PackageScaffolder($this->temporaryRoot()))->create('WPCli', 'stellarwp/foundation-wp-cli');

		$this->assertSame('stellarwp/foundation-wp-cli', $scaffold->package->name);
	}

	public function test_it_skips_scaffold_files_that_already_exist(): void {
		$rootPath = $this->temporaryRoot();
		$path     = $rootPath . '/src/WPCli';

		mkdir($path, 0777, true);
		file_put_contents($path . '/composer.json', '{}');

		$scaffold = (new PackageScaffolder($rootPath))->create('WPCli', 'stellarwp/foundation-wpcli');

		$this->assertNotContains('composer.json', $scaffold->createdFiles);
		$this->assertSame('{}', file_get_contents($path . '/composer.json'));
	}

	public function test_it_rejects_invalid_package_names(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Invalid Foundation package name');

		(new PackageScaffolder($this->temporaryRoot()))->create('WPCli', 'stellarwp/not-foundation-wpcli');
	}

	public function test_it_fails_when_the_target_path_is_a_file(): void {
		$rootPath = $this->temporaryRoot();

		mkdir($rootPath . '/src', 0777, true);
		file_put_contents($rootPath . '/src/WPCli', 'not a directory');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('already exists and is not a directory');

		(new PackageScaffolder($rootPath))->create('WPCli', 'stellarwp/foundation-wpcli');
	}

	private function temporaryRoot(): string {
		$root = $this->tempDir . '/foundation-cli-test-' . bin2hex(random_bytes(8));

		if (! mkdir($root, 0777, true) && ! is_dir($root)) {
			$this->fail(sprintf('Could not create temporary root "%s".', $root));
		}

		$this->temporaryRoots[] = $root;

		return $root;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function packageComposer(string $rootPath, string $component): array {
		$composer = json_decode((string) file_get_contents($rootPath . '/src/' . $component . '/composer.json'), true);

		if (! is_array($composer)) {
			$this->fail(sprintf('Could not decode composer.json for "%s".', $component));
		}

		return $composer;
	}

	private function writePackageComposer(string $rootPath, string $component, string $branchAlias): void {
		$path = $rootPath . '/src/' . $component;

		if (! mkdir($path, 0777, true) && ! is_dir($path)) {
			$this->fail(sprintf('Could not create package directory "%s".', $path));
		}

		file_put_contents(
			$path . '/composer.json',
			(string) json_encode([
				'name'  => 'stellarwp/foundation-' . strtolower($component),
				'extra' => [
					'branch-alias' => [
						'dev-main' => $branchAlias,
					],
				],
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);
	}

	private function writeMalformedPackageComposer(string $rootPath, string $component): void {
		$path = $rootPath . '/src/' . $component;

		if (! mkdir($path, 0777, true) && ! is_dir($path)) {
			$this->fail(sprintf('Could not create package directory "%s".', $path));
		}

		file_put_contents($path . '/composer.json', '{');
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
