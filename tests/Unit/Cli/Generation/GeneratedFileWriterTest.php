<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Generation;

use phpmock\mockery\PHPMockery;
use PhpParser\Lexer;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
use StellarWP\Foundation\Cli\Generation\Php\PhpSourceEditor;
use StellarWP\Foundation\Cli\Generation\ValueObjects\GeneratedFile;
use StellarWP\Foundation\Tests\TestCase;

final class GeneratedFileWriterTest extends TestCase
{
	private string $tempDir;

	protected function setUp(): void {
		parent::setUp();

		$this->tempDir = $this->prepare_temp_dir('generated-file-writer');
	}

	public function test_it_writes_generated_files_to_nested_directories(): void {
		$file = new GeneratedFile(
			path: $this->tempDir . '/nested/Generated.php',
			relativePath: 'nested/Generated.php',
			contents: '<?php echo "generated";'
		);

		$this->writer()->write($file);

		$this->assertFileExists($file->path);
		$this->assertSame($file->contents, (string) file_get_contents($file->path));
	}

	public function test_it_validates_every_file_before_writing_a_batch(): void {
		$existingPath = $this->tempDir . '/Existing.php';

		file_put_contents($existingPath, 'existing');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('File already exists: Existing.php.');

		try {
			$this->writer()->writeAll(
				new GeneratedFile(
					path: $this->tempDir . '/First.php',
					relativePath: 'First.php',
					contents: '<?php // first'
				),
				new GeneratedFile(
					path: $existingPath,
					relativePath: 'Existing.php',
					contents: '<?php // replacement'
				)
			);
		} finally {
			$this->assertFileDoesNotExist($this->tempDir . '/First.php');
			$this->assertSame('existing', (string) file_get_contents($existingPath));
		}
	}

	public function test_it_removes_files_written_before_a_later_batch_write_fails(): void {
		$blockedPath = $this->tempDir . '/blocked';

		file_put_contents($blockedPath, 'file');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(sprintf('Could not create directory "%s".', $blockedPath));

		set_error_handler(static fn (): bool => true);

		try {
			$this->writer()->writeAll(
				new GeneratedFile(
					path: $this->tempDir . '/First.php',
					relativePath: 'First.php',
					contents: '<?php // first'
				),
				new GeneratedFile(
					path: $blockedPath . '/Second.php',
					relativePath: 'blocked/Second.php',
					contents: '<?php // second'
				)
			);
		} finally {
			restore_error_handler();
			$this->assertFileDoesNotExist($this->tempDir . '/First.php');
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_reports_when_a_batch_write_and_its_cleanup_both_fail(): void {
		$firstPath   = $this->tempDir . '/First.php';
		$blockedPath = $this->tempDir . '/blocked';

		file_put_contents($blockedPath, 'file');

		PHPMockery::mock('StellarWP\Foundation\Cli\Generation', 'unlink')
			->with($firstPath)
			->once()
			->andReturn(false);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(sprintf(
			'Could not create directory "%s". Could not remove generated files after the operation failed: First.php.',
			$blockedPath
		));

		set_error_handler(static fn (): bool => true);

		try {
			$this->writer()->writeAll(
				new GeneratedFile(
					path: $firstPath,
					relativePath: 'First.php',
					contents: '<?php // first'
				),
				new GeneratedFile(
					path: $blockedPath . '/Second.php',
					relativePath: 'blocked/Second.php',
					contents: '<?php // second'
				)
			);
		} finally {
			restore_error_handler();
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_reports_generated_files_that_cannot_be_removed(): void {
		$path = $this->tempDir . '/Generated.php';

		file_put_contents($path, '<?php // generated');

		PHPMockery::mock('StellarWP\Foundation\Cli\Generation', 'unlink')
			->with($path)
			->once()
			->andReturn(false);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not remove generated files after the operation failed: Generated.php.');

		$this->writer()->remove(new GeneratedFile(
			path: $path,
			relativePath: 'Generated.php',
			contents: '<?php // generated'
		));
	}

	public function test_it_rejects_invalid_php_before_creating_the_file(): void {
		$file = new GeneratedFile(
			path: $this->tempDir . '/Invalid.php',
			relativePath: 'Invalid.php',
			contents: '<?php final class Invalid {'
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Generated file "Invalid.php" is not valid PHP.');

		try {
			$this->writer()->write($file);
		} finally {
			$this->assertFileDoesNotExist($file->path);
		}
	}

	public function test_it_rejects_case_insensitive_class_import_collisions_before_creating_the_file(): void {
		$file = new GeneratedFile(
			path: $this->tempDir . '/Migration.php',
			relativePath: 'Migration.php',
			contents: (string) file_get_contents($this->data_dir('cli/generation/generated-file-writer/class-import-collision.stub'))
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('declares or imports "migration" more than once');

		try {
			$this->writer()->write($file);
		} finally {
			$this->assertFileDoesNotExist($file->path);
		}
	}

	public function test_it_rejects_duplicate_identical_imports_before_creating_the_file(): void {
		$file = new GeneratedFile(
			path: $this->tempDir . '/Example.php',
			relativePath: 'Example.php',
			contents: (string) file_get_contents($this->data_dir('cli/generation/generated-file-writer/duplicate-import.stub'))
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('declares or imports "Migration" more than once');

		try {
			$this->writer()->write($file);
		} finally {
			$this->assertFileDoesNotExist($file->path);
		}
	}

	public function test_it_rejects_duplicate_class_declarations_before_creating_the_file(): void {
		$file = new GeneratedFile(
			path: $this->tempDir . '/Duplicate.php',
			relativePath: 'Duplicate.php',
			contents: (string) file_get_contents($this->data_dir('cli/generation/generated-file-writer/duplicate-class.stub'))
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('declares or imports "duplicate" more than once');

		try {
			$this->writer()->write($file);
		} finally {
			$this->assertFileDoesNotExist($file->path);
		}
	}

	public function test_it_refuses_to_overwrite_existing_files_without_force(): void {
		$path = $this->tempDir . '/Generated.php';

		file_put_contents($path, 'existing');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('File already exists: Generated.php.');

		$this->writer()->write(new GeneratedFile(
			path: $path,
			relativePath: 'Generated.php',
			contents: '<?php // replacement'
		));
	}

	public function test_it_overwrites_existing_files_when_forced(): void {
		$path = $this->tempDir . '/Generated.php';

		file_put_contents($path, 'existing');

		$this->writer()->write(new GeneratedFile(
			path: $path,
			relativePath: 'Generated.php',
			contents: '<?php // replacement'
		), force: true);

		$this->assertSame('<?php // replacement', (string) file_get_contents($path));
	}

	public function test_forced_creation_uses_normal_process_file_permissions(): void {
		$path = $this->tempDir . '/Generated.php';

		$this->writer()->write(new GeneratedFile(
			path: $path,
			relativePath: 'Generated.php',
			contents: '<?php // generated'
		), force: true);

		$this->assertSame(0666 & ~umask(), fileperms($path) & 0777);
	}

	public function test_forced_writes_preserve_symbolic_links(): void {
		$target = $this->tempDir . '/Target.php';
		$link   = $this->tempDir . '/Generated.php';

		file_put_contents($target, 'existing');

		$this->assertTrue(symlink($target, $link));

		$this->writer()->write(new GeneratedFile(
			path: $link,
			relativePath: 'Generated.php',
			contents: '<?php // replacement'
		), force: true);

		$this->assertTrue(is_link($link));
		$this->assertSame('<?php // replacement', (string) file_get_contents($target));
	}

	public function test_forced_writes_create_the_target_of_a_dangling_symbolic_link(): void {
		$target = $this->tempDir . '/Target.php';
		$link   = $this->tempDir . '/Generated.php';

		$this->assertTrue(symlink('Target.php', $link));

		$this->writer()->write(new GeneratedFile(
			path: $link,
			relativePath: 'Generated.php',
			contents: '<?php // generated'
		), force: true);

		$this->assertTrue(is_link($link));
		$this->assertSame('<?php // generated', (string) file_get_contents($target));
	}

	public function test_forced_writes_preserve_a_chain_of_dangling_symbolic_links(): void {
		$target = $this->tempDir . '/Target.php';
		$middle = $this->tempDir . '/Middle.php';
		$link   = $this->tempDir . '/Generated.php';

		$this->assertTrue(symlink('Target.php', $middle));
		$this->assertTrue(symlink('Middle.php', $link));

		$this->writer()->write(new GeneratedFile(
			path: $link,
			relativePath: 'Generated.php',
			contents: '<?php // generated'
		), force: true);

		$this->assertTrue(is_link($link));
		$this->assertTrue(is_link($middle));
		$this->assertSame('<?php // generated', (string) file_get_contents($target));
	}

	public function test_forced_writes_reject_symbolic_link_cycles(): void {
		$first  = $this->tempDir . '/First.php';
		$second = $this->tempDir . '/Second.php';

		$this->assertTrue(symlink('Second.php', $first));
		$this->assertTrue(symlink('First.php', $second));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not write generated file "First.php".');

		$this->writer()->write(new GeneratedFile(
			path: $first,
			relativePath: 'First.php',
			contents: '<?php // generated'
		), force: true);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_rejects_short_forced_writes(): void {
		$path     = $this->tempDir . '/Generated.php';
		$tempPath = $this->tempDir . '/temporary.php';
		$contents = '<?php // replacement';

		PHPMockery::mock('StellarWP\Foundation\Cli\Generation', 'tempnam')
			->with($this->tempDir, '.foundation-write-')
			->once()
			->andReturn($tempPath);
		PHPMockery::mock('StellarWP\Foundation\Cli\Generation', 'file_put_contents')
			->with($tempPath, $contents)
			->once()
			->andReturn(3);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not write generated file "Generated.php".');

		$this->writer()->write(new GeneratedFile(
			path: $path,
			relativePath: 'Generated.php',
			contents: $contents
		), force: true);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_fails_when_a_file_cannot_be_created_exclusively(): void {
		$path = $this->tempDir . '/Generated.php';

		PHPMockery::mock('StellarWP\Foundation\Cli\Generation', 'fopen')
			->with($path, 'x')
			->once()
			->andReturn(false);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not write generated file "Generated.php".');

		$this->writer()->write(new GeneratedFile(
			path: $path,
			relativePath: 'Generated.php',
			contents: '<?php // content'
		));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_removes_partially_written_generated_files(): void {
		$path   = $this->tempDir . '/Generated.php';
		$handle = fopen('php://temp', 'w+');

		$this->assertIsResource($handle);
		file_put_contents($path, 'partial');

		PHPMockery::mock('StellarWP\Foundation\Cli\Generation', 'fopen')
			->with($path, 'x')
			->once()
			->andReturn($handle);
		PHPMockery::mock('StellarWP\Foundation\Cli\Generation', 'fwrite')
			->with($handle, '<?php // content')
			->once()
			->andReturn(3);
		PHPMockery::mock('StellarWP\Foundation\Cli\Generation', 'fclose')
			->with($handle)
			->once()
			->andReturn(true);
		PHPMockery::mock('StellarWP\Foundation\Cli\Generation', 'unlink')
			->with($path)
			->once()
			->andReturn(true);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not write generated file "Generated.php".');

		try {
			$this->writer()->write(new GeneratedFile(
				path: $path,
				relativePath: 'Generated.php',
				contents: '<?php // content'
			));
		} finally {
			\fclose($handle);
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_reports_when_a_partial_generated_file_cannot_be_removed(): void {
		$path   = $this->tempDir . '/Generated.php';
		$handle = fopen('php://temp', 'w+');

		$this->assertIsResource($handle);
		file_put_contents($path, 'partial');

		PHPMockery::mock('StellarWP\Foundation\Cli\Generation', 'fopen')
			->with($path, 'x')
			->once()
			->andReturn($handle);
		PHPMockery::mock('StellarWP\Foundation\Cli\Generation', 'fwrite')
			->with($handle, '<?php // content')
			->once()
			->andReturn(3);
		PHPMockery::mock('StellarWP\Foundation\Cli\Generation', 'fclose')
			->with($handle)
			->once()
			->andReturn(true);
		PHPMockery::mock('StellarWP\Foundation\Cli\Generation', 'unlink')
			->with($path)
			->once()
			->andReturn(false);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(
			'Could not write generated file "Generated.php". Could not remove generated files after the operation failed: Generated.php.'
		);

		try {
			$this->writer()->write(new GeneratedFile(
				path: $path,
				relativePath: 'Generated.php',
				contents: '<?php // content'
			));
		} finally {
			\fclose($handle);
		}
	}

	public function test_it_fails_when_the_target_directory_cannot_be_created(): void {
		$path = $this->tempDir . '/blocked';

		file_put_contents($path, 'file');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(sprintf('Could not create directory "%s/Generated.php".', $path));

		set_error_handler(static fn (): bool => true);

		try {
			$this->writer()->write(new GeneratedFile(
				path: $path . '/Generated.php/File.php',
				relativePath: 'blocked/Generated.php/File.php',
				contents: '<?php // content'
			));
		} finally {
			restore_error_handler();
		}
	}

	public function test_it_fails_when_the_generated_file_cannot_be_written(): void {
		$path = $this->tempDir . '/Generated.php';

		mkdir($path);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not write generated file "Generated.php".');

		set_error_handler(static fn (): bool => true);

		try {
			$this->writer()->write(new GeneratedFile(
				path: $path,
				relativePath: 'Generated.php',
				contents: '<?php // content'
			), force: true);
		} finally {
			restore_error_handler();
		}
	}

	private function writer(): GeneratedFileWriter {
		return new GeneratedFileWriter(new PhpSourceEditor(new ParserFactory(), new Lexer()));
	}
}
