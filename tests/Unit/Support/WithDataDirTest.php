<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Support;

use InvalidArgumentException;
use StellarWP\Foundation\Tests\TestCase;

final class WithDataDirTest extends TestCase
{
	public function test_it_prepares_unique_temp_directories_under_a_named_temp_path(): void {
		$first  = $this->prepare_temp_dir('with-data-dir');
		$second = $this->prepare_temp_dir('with-data-dir');

		$this->assertDirectoryExists($first);
		$this->assertDirectoryExists($second);
		$this->assertNotSame($first, $second);
		$this->assertStringStartsWith($this->temp_dir('with-data-dir') . '/', $first);
		$this->assertStringStartsWith($this->temp_dir('with-data-dir') . '/', $second);

		$this->remove_temp_dir('with-data-dir');

		$this->assertDirectoryDoesNotExist($this->temp_dir('with-data-dir'));
	}

	public function test_it_rejects_empty_named_temp_directories(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A test-specific temp directory name is required.');

		$this->prepare_temp_dir('');
	}

	public function test_it_rejects_temp_paths_that_escape_the_shared_temp_directory(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Temporary test directory paths cannot contain "." or ".." segments.');

		$this->prepare_temp_dir('../outside');
	}

	public function test_it_rejects_temp_paths_that_resolve_outside_the_shared_temp_directory(): void {
		if (! function_exists('symlink')) {
			$this->markTestSkipped('Symlinks are not available.');
		}

		$outside = $this->data_dir('outside-temp-target-' . bin2hex(random_bytes(8)));
		$link    = $this->temp_dir('linked-outside');

		mkdir($outside, 0777, true);
		symlink($outside, $link);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot contain symlinked path segments');

		try {
			$this->prepare_temp_dir('linked-outside');
		} finally {
			unlink($link);
			rmdir($outside);
		}
	}

	public function test_it_rejects_nested_symlinked_temp_path_segments_before_creating_children(): void {
		if (! function_exists('symlink')) {
			$this->markTestSkipped('Symlinks are not available.');
		}

		$outside = $this->data_dir('outside-temp-target-' . bin2hex(random_bytes(8)));
		$parent  = $this->temp_dir('nested-link');
		$link    = $parent . '/linked-outside';

		mkdir($outside, 0777, true);
		mkdir($parent, 0777, true);
		symlink($outside, $link);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot contain symlinked path segments');

		try {
			$this->prepare_temp_dir('nested-link/linked-outside/new');
		} finally {
			$this->assertDirectoryDoesNotExist($outside . '/new');

			unlink($link);
			rmdir($parent);
			rmdir($outside);
		}
	}
}
