<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Traits;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use StellarWP\Foundation\Tests\TestCase;

/**
 * @mixin TestCase
 */
trait WithDataDir
{
	/**
	 * @var list<string>
	 */
	private array $preparedTempDirs = [];

	/**
	 * Retrieve a path from the tests data directory.
	 */
	protected function data_dir(string $appendPath = ''): string {
		return $this->container->get(TestCase::DATA_DIR) . $appendPath;
	}

	/**
	 * Retrieve a path from the writable tests temp data directory.
	 */
	protected function temp_dir(string $appendPath = ''): string {
		return $this->temp_path($appendPath);
	}

	/**
	 * Create a clean, test-owned directory inside the writable tests temp directory.
	 */
	protected function prepare_temp_dir(string $appendPath): string {
		$parent = $this->named_temp_dir($appendPath);

		$this->create_temp_parent($parent);
		$this->assert_temp_path_is_contained($parent);

		$directory = $parent . '/' . bin2hex(random_bytes(8));

		if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
			throw new RuntimeException(sprintf('Could not create temporary test directory "%s".', $directory));
		}

		$this->preparedTempDirs[] = $directory;

		return $directory;
	}

	/**
	 * Remove a test-owned directory from the writable tests temp directory.
	 */
	protected function remove_temp_dir(string $appendPath): void {
		$directory = $this->named_temp_dir($appendPath) . '/';
		$remaining = [];

		foreach ($this->preparedTempDirs as $preparedTempDir) {
			if (str_starts_with($preparedTempDir . '/', $directory)) {
				$this->remove_directory($preparedTempDir);
				$this->remove_empty_temp_parent($preparedTempDir);

				continue;
			}

			$remaining[] = $preparedTempDir;
		}

		$this->preparedTempDirs = $remaining;
	}

	protected function cleanup_temp_dirs(): void {
		foreach (array_reverse($this->preparedTempDirs) as $directory) {
			$this->remove_directory($directory);
			$this->remove_empty_temp_parent($directory);
		}

		$this->preparedTempDirs = [];
	}

	private function named_temp_dir(string $appendPath): string {
		if (trim($appendPath, '/') === '') {
			throw new InvalidArgumentException('A test-specific temp directory name is required.');
		}

		return $this->temp_dir($appendPath);
	}

	private function temp_path(string $appendPath): string {
		$base = rtrim($this->data_dir('temp'), '/');
		$path = str_replace('\\', '/', $appendPath);

		if (trim($path, '/') === '') {
			return $base;
		}

		if (str_starts_with($path, '/')) {
			throw new InvalidArgumentException('Temporary test directory paths must be relative.');
		}

		$segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));

		foreach ($segments as $segment) {
			if ($segment === '.' || $segment === '..') {
				throw new InvalidArgumentException('Temporary test directory paths cannot contain "." or ".." segments.');
			}
		}

		return $base . '/' . implode('/', $segments);
	}

	private function create_temp_parent(string $directory): void {
		$base     = $this->temp_dir();
		$path     = $base;
		$relative = trim(substr($directory, strlen($base)), '/');

		if ($relative === '') {
			return;
		}

		foreach (explode('/', $relative) as $segment) {
			$path .= '/' . $segment;

			if (is_link($path)) {
				throw new InvalidArgumentException(sprintf('Temporary test directory "%s" cannot contain symlinked path segments.', $directory));
			}

			if (! is_dir($path) && ! mkdir($path, 0777) && ! is_dir($path)) {
				throw new RuntimeException(sprintf('Could not create temporary test directory "%s".', $path));
			}
		}
	}

	private function remove_directory(string $directory): void {
		if (! is_dir($directory)) {
			return;
		}

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
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

	private function assert_temp_path_is_contained(string $directory): void {
		$base = realpath($this->temp_dir());
		$path = realpath($directory);

		if ($base === false || $path === false) {
			return;
		}

		if ($path !== $base && ! str_starts_with($path . '/', $base . '/')) {
			throw new InvalidArgumentException(sprintf('Temporary test directory "%s" must be inside "%s".', $directory, $base));
		}
	}

	private function remove_empty_temp_parent(string $directory): void {
		$parent = dirname($directory);
		$base   = $this->temp_dir();

		if ($parent === $base || ! str_starts_with($parent . '/', $base . '/') || ! is_dir($parent)) {
			return;
		}

		@rmdir($parent);
	}
}
