<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Generation;

use phpmock\mockery\PHPMockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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
		$this->expectExceptionMessage('File already exists: Generated.php.');

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

		(new GeneratedFileWriter())->write(new GeneratedFile(
			path: $path,
			relativePath: 'Generated.php',
			contents: 'content'
		));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_removes_partially_written_generated_files(): void {
		$path   = $this->tempDir . '/Generated.php';
		$handle = fopen('php://temp', 'w+');

		$this->assertIsResource($handle);

		PHPMockery::mock('StellarWP\Foundation\Cli\Generation', 'fopen')
			->with($path, 'x')
			->once()
			->andReturn($handle);
		PHPMockery::mock('StellarWP\Foundation\Cli\Generation', 'fwrite')
			->with($handle, 'content')
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
			(new GeneratedFileWriter())->write(new GeneratedFile(
				path: $path,
				relativePath: 'Generated.php',
				contents: 'content'
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
