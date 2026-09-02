<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation;

use RuntimeException;
use StellarWP\Foundation\Cli\Generation\Php\PhpSourceEditor;
use StellarWP\Foundation\Cli\Generation\ValueObjects\GeneratedFile;

/**
 * Writes generated files to disk with overwrite protection.
 */
final readonly class GeneratedFileWriter
{
	/**
	 * Create a writer that validates generated PHP before touching the filesystem.
	 */
	public function __construct(
		private PhpSourceEditor $sourceEditor
	) {
	}

	/**
	 * Validate generated PHP before any filesystem changes are made.
	 *
	 * @throws RuntimeException When the generated PHP is invalid or contains an import collision.
	 */
	public function validate(GeneratedFile $file): void {
		if (! $this->sourceEditor->canParse($file->contents)) {
			throw new RuntimeException(sprintf('Generated file "%s" is not valid PHP.', $file->relativePath));
		}

		$collision = $this->sourceEditor->classImportCollision($file->contents);

		if ($collision !== null) {
			throw new RuntimeException(sprintf(
				'Generated file "%s" declares or imports "%s" more than once.',
				$file->relativePath,
				$collision
			));
		}
	}

	/**
	 * Write a related set of generated files, removing earlier files if a later write fails.
	 *
	 * @throws RuntimeException When any file is invalid, already exists, or cannot be written.
	 */
	public function writeAll(GeneratedFile ...$files): void {
		foreach ($files as $file) {
			$this->validate($file);

			if (file_exists($file->path)) {
				throw new RuntimeException(sprintf('File already exists: %s.', $file->relativePath));
			}
		}

		$writtenFiles = [];

		try {
			foreach ($files as $file) {
				$this->write($file);
				$writtenFiles[] = $file;
			}
		} catch (RuntimeException $exception) {
			try {
				$this->remove(...$writtenFiles);
			} catch (RuntimeException $cleanupException) {
				throw new RuntimeException(
					$exception->getMessage() . ' ' . $cleanupException->getMessage(),
					0,
					$exception
				);
			}

			throw $exception;
		}
	}

	/**
	 * Remove generated files created by an operation that could not be completed.
	 *
	 * @throws RuntimeException When one or more generated files cannot be removed.
	 */
	public function remove(GeneratedFile ...$files): void {
		$failures = [];

		foreach ($files as $file) {
			if (file_exists($file->path) && ! @unlink($file->path)) {
				$failures[] = $file->relativePath;
			}
		}

		if ($failures !== []) {
			throw new RuntimeException(sprintf(
				'Could not remove generated files after the operation failed: %s.',
				implode(', ', $failures)
			));
		}
	}

	/**
	 * Write one generated file with optional overwrite behavior.
	 *
	 * @throws RuntimeException When the file is invalid, already exists, or cannot be written.
	 */
	public function write(GeneratedFile $file, bool $force = false): void {
		$this->validate($file);

		$directory = dirname($file->path);

		if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
			throw new RuntimeException(sprintf('Could not create directory "%s".', $directory));
		}

		if ($force) {
			if (! $this->replace($file->path, $file->contents)) {
				throw new RuntimeException(sprintf('Could not write generated file "%s".', $file->relativePath));
			}

			return;
		}

		$handle = @fopen($file->path, 'x');

		if ($handle === false) {
			if (file_exists($file->path)) {
				throw new RuntimeException(sprintf('File already exists: %s.', $file->relativePath));
			}

			throw new RuntimeException(sprintf('Could not write generated file "%s".', $file->relativePath));
		}

		$written = fwrite($handle, $file->contents);
		$closed  = fclose($handle);

		if ($written !== strlen($file->contents) || ! $closed) {
			$message = sprintf('Could not write generated file "%s".', $file->relativePath);

			try {
				$this->remove($file);
			} catch (RuntimeException $cleanupException) {
				$message .= ' ' . $cleanupException->getMessage();
			}

			throw new RuntimeException($message);
		}
	}

	/**
	 * Atomically replace a file while preserving its permissions and symlink target.
	 */
	private function replace(string $path, string $contents): bool {
		$path = $this->targetPath($path);

		if ($path === null) {
			return false;
		}

		$temporaryPath = tempnam(dirname($path), '.foundation-write-');

		if ($temporaryPath === false) {
			return false;
		}

		try {
			$written = file_put_contents($temporaryPath, $contents);

			if ($written !== strlen($contents)) {
				return false;
			}

			$permissions = file_exists($path)
				? fileperms($path)
				: 0666 & ~umask();

			if ($permissions === false || ! chmod($temporaryPath, $permissions & 0777)) {
				return false;
			}

			return @rename($temporaryPath, $path);
		} finally {
			if (file_exists($temporaryPath)) {
				@unlink($temporaryPath);
			}
		}
	}

	/**
	 * Follow a symlink chain to the writable target, rejecting loops and broken links.
	 */
	private function targetPath(string $path): ?string {
		$seen = [];

		while (is_link($path)) {
			if (isset($seen[$path])) {
				return null;
			}

			$seen[$path] = true;
			$target      = readlink($path);

			if ($target === false) {
				return null;
			}

			$targetPath = str_starts_with($target, '/')
				? $target
				: dirname($path) . '/' . $target;
			$targetDirectory = realpath(dirname($targetPath));

			if ($targetDirectory === false) {
				return null;
			}

			$path = $targetDirectory . '/' . basename($targetPath);
		}

		return $path;
	}
}
