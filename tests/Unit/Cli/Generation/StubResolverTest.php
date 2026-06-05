<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Generation;

use StellarWP\Foundation\Cli\Generation\StubResolver;
use StellarWP\Foundation\Tests\TestCase;

final class StubResolverTest extends TestCase
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

	public function test_it_uses_a_project_override_before_the_default_stub(): void {
		$root = $this->temporaryRoot();

		mkdir($root . '/foundation/stubs/wpcli', 0777, true);
		file_put_contents($root . '/foundation/stubs/wpcli/command.stub', 'override');

		$this->assertSame(
			$root . '/foundation/stubs/wpcli/command.stub',
			(new StubResolver($root))->resolve('wpcli', 'command', '/default/command.stub')
		);
	}

	public function test_it_uses_the_default_stub_when_no_override_exists(): void {
		$this->assertSame(
			'/default/command.stub',
			(new StubResolver($this->temporaryRoot()))->resolve('wpcli', 'command', '/default/command.stub')
		);
	}

	private function temporaryRoot(): string {
		$root = sys_get_temp_dir() . '/foundation-stub-test-' . bin2hex(random_bytes(8));

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
