<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation;

use RuntimeException;
use StellarWP\Foundation\Cli\Generation\ValueObjects\GeneratedFile;

/**
 * Writes generated files to disk with overwrite protection.
 */
final class GeneratedFileWriter
{
	public function write(GeneratedFile $file, bool $force = false): void {
		$directory = dirname($file->path);

		if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
			throw new RuntimeException(sprintf('Could not create directory "%s".', $directory));
		}

		if ($force) {
			if (file_put_contents($file->path, $file->contents) === false) {
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
			@unlink($file->path);

			throw new RuntimeException(sprintf('Could not write generated file "%s".', $file->relativePath));
		}
	}
}
