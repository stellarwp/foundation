<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Generation\ValueObjects;

use InvalidArgumentException;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use StellarWP\Foundation\Tests\TestCase;

final class ProjectDirectoryTest extends TestCase
{
	public function test_it_resolves_project_relative_and_absolute_paths(): void {
		$directory = new ProjectDirectory('/workspace/project/');

		$this->assertSame('/workspace/project/src/Feature.php', $directory->absolutePath('src/Feature.php'));
		$this->assertSame('/tmp/Feature.php', $directory->absolutePath('/tmp/Feature.php'));
		$this->assertSame('/workspace/project', $directory->absolutePath(''));
	}

	public function test_it_returns_project_relative_display_paths_when_possible(): void {
		$directory = new ProjectDirectory('/workspace/project');

		$this->assertSame('src/Feature.php', $directory->relativePath('/workspace/project/src/Feature.php'));
		$this->assertSame('/tmp/Feature.php', $directory->relativePath('/tmp/Feature.php'));
	}

	public function test_it_resolves_paths_from_the_filesystem_root(): void {
		$directory = new ProjectDirectory('/');

		$this->assertSame('/composer.json', $directory->absolutePath('composer.json'));
		$this->assertSame('/', $directory->absolutePath('/'));
		$this->assertSame('composer.json', $directory->relativePath('/composer.json'));
	}

	public function test_it_preserves_legal_whitespace_in_the_project_directory(): void {
		$directory = new ProjectDirectory('/workspace/project ');

		$this->assertSame('/workspace/project /composer.json', $directory->absolutePath('composer.json'));
	}

	public function test_it_recognizes_windows_absolute_paths(): void {
		$directory = new ProjectDirectory('C:\\workspace\\project');

		$this->assertSame('D:\\output\\Feature.php', $directory->absolutePath('D:\\output\\Feature.php'));
	}

	public function test_it_rejects_an_empty_project_directory(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The project directory cannot be empty.');

		new ProjectDirectory('  ');
	}
}
