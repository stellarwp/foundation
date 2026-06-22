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
		return rtrim($this->data_dir('temp/' . trim($appendPath, '/')), '/');
	}

	/**
	 * Create a clean, test-owned directory inside the writable tests temp directory.
	 */
	protected function prepare_temp_dir(string $appendPath): string {
		$directory = $this->named_temp_dir($appendPath);

		$this->remove_directory($directory);

		if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
			throw new RuntimeException(sprintf('Could not create temporary test directory "%s".', $directory));
		}

		$this->preparedTempDirs[] = $directory;

		return $directory;
	}

	/**
	 * Remove a test-owned directory from the writable tests temp directory.
	 */
	protected function remove_temp_dir(string $appendPath): void {
		$directory = $this->named_temp_dir($appendPath);

		$this->remove_directory($directory);

		$this->preparedTempDirs = array_values(array_filter(
			$this->preparedTempDirs,
			static fn (string $preparedTempDir): bool => $preparedTempDir !== $directory
		));
	}

	protected function cleanup_temp_dirs(): void {
		foreach (array_reverse($this->preparedTempDirs) as $directory) {
			$this->remove_directory($directory);
		}

		$this->preparedTempDirs = [];
	}

	private function named_temp_dir(string $appendPath): string {
		if (trim($appendPath, '/') === '') {
			throw new InvalidArgumentException('A test-specific temp directory name is required.');
		}

		return $this->temp_dir($appendPath);
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
}
