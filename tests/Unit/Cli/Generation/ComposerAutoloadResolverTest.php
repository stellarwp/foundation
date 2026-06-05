<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Generation;

use RuntimeException;
use StellarWP\Foundation\Cli\Generation\ComposerAutoloadResolver;
use StellarWP\Foundation\Tests\TestCase;

final class ComposerAutoloadResolverTest extends TestCase
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

	public function test_it_resolves_the_first_psr4_namespace(): void {
		$root = $this->temporaryRoot([
			'autoload' => [
				'psr-4' => [
					'Acme\\Plugin\\' => 'src',
				],
			],
		]);

		$namespace = (new ComposerAutoloadResolver($root))->firstPsr4Namespace();

		$this->assertSame('Acme\\Plugin\\', $namespace->namespace);
		$this->assertSame('src', $namespace->path);
	}

	public function test_it_fails_when_composer_json_is_missing(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not find composer.json');

		(new ComposerAutoloadResolver($this->temporaryRoot()))->firstPsr4Namespace();
	}

	public function test_it_fails_when_composer_json_has_no_psr4_autoload(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not find an autoload.psr-4 namespace in composer.json.');

		(new ComposerAutoloadResolver($this->temporaryRoot([
			'autoload' => [],
		])))->firstPsr4Namespace();
	}

	public function test_it_fails_when_psr4_autoload_has_no_valid_path(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not find a valid autoload.psr-4 namespace in composer.json.');

		(new ComposerAutoloadResolver($this->temporaryRoot([
			'autoload' => [
				'psr-4' => [
					'Acme\\Plugin\\' => '',
				],
			],
		])))->firstPsr4Namespace();
	}

	/**
	 * @param array<string,mixed>|null $composer
	 */
	private function temporaryRoot(?array $composer = null): string {
		$root = sys_get_temp_dir() . '/foundation-autoload-test-' . bin2hex(random_bytes(8));

		if (! mkdir($root, 0777, true) && ! is_dir($root)) {
			$this->fail(sprintf('Could not create temporary root "%s".', $root));
		}

		if ($composer !== null) {
			file_put_contents($root . '/composer.json', (string) json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
