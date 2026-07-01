<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Generation;

use RuntimeException;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
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

		(new GeneratedFileWriter())->write($file);

		$this->assertFileExists($file->path);
		$this->assertSame($file->contents, (string) file_get_contents($file->path));
	}

	public function test_it_refuses_to_overwrite_existing_files_without_force(): void {
		$path = $this->tempDir . '/Generated.php';

		file_put_contents($path, 'existing');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('File already exists: Generated.php. Use --force to overwrite it.');

		(new GeneratedFileWriter())->write(new GeneratedFile(
			path: $path,
			relativePath: 'Generated.php',
			contents: 'replacement'
		));
	}

	public function test_it_overwrites_existing_files_when_forced(): void {
		$path = $this->tempDir . '/Generated.php';

		file_put_contents($path, 'existing');

		(new GeneratedFileWriter())->write(new GeneratedFile(
			path: $path,
			relativePath: 'Generated.php',
			contents: 'replacement'
		), force: true);

		$this->assertSame('replacement', (string) file_get_contents($path));
	}

	public function test_it_fails_when_the_target_directory_cannot_be_created(): void {
		$path = $this->tempDir . '/blocked';

		file_put_contents($path, 'file');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(sprintf('Could not create directory "%s/Generated.php".', $path));

		set_error_handler(static fn (): bool => true);

		try {
			(new GeneratedFileWriter())->write(new GeneratedFile(
				path: $path . '/Generated.php/File.php',
				relativePath: 'blocked/Generated.php/File.php',
				contents: 'content'
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
			(new GeneratedFileWriter())->write(new GeneratedFile(
				path: $path,
				relativePath: 'Generated.php',
				contents: 'content'
			), force: true);
		} finally {
			restore_error_handler();
		}
	}
}
