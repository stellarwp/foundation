<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation\ValueObjects;

use InvalidArgumentException;

/**
 * Represents the consuming project's root directory for generated paths.
 */
final readonly class ProjectDirectory
{
	public string $path;

	/**
	 * Normalize the project root used by generator path operations.
	 *
	 * @throws InvalidArgumentException When the project directory is empty.
	 */
	public function __construct(string $path) {
		if (trim($path) === '') {
			throw new InvalidArgumentException('The project directory cannot be empty.');
		}

		$this->path = $this->withoutTrailingDirectorySeparator($path);
	}

	/**
	 * Resolve a project-relative path without changing an absolute path.
	 */
	public function absolutePath(string $path): string {
		$path = trim($path);

		if ($this->isAbsolutePath($path)) {
			return $this->withoutTrailingDirectorySeparator($path);
		}

		$path = trim($path, '/\\');

		if ($path === '') {
			return $this->path;
		}

		$separator = str_ends_with($this->path, '/') || str_ends_with($this->path, '\\') ? '' : '/';

		return $this->path . $separator . $path;
	}

	/**
	 * Return a project-relative display path when the path is under the project root.
	 */
	public function relativePath(string $path): string {
		$normalizedPath = str_replace('\\', '/', $path);
		$normalizedRoot = rtrim(str_replace('\\', '/', $this->path), '/');
		$root           = ($normalizedRoot === '' ? '' : $normalizedRoot) . '/';

		if (str_starts_with($normalizedPath, $root)) {
			return substr($normalizedPath, strlen($root));
		}

		return $path;
	}

	/**
	 * Determine whether a path is absolute on POSIX or Windows filesystems.
	 */
	private function isAbsolutePath(string $path): bool {
		return str_starts_with($path, '/')
			|| str_starts_with($path, '\\')
			|| preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
	}

	/**
	 * Remove trailing separators without collapsing a filesystem root.
	 */
	private function withoutTrailingDirectorySeparator(string $path): string {
		if ($path === '/' || $path === '\\' || preg_match('/^[A-Za-z]:[\\\\\\/]$/', $path) === 1) {
			return $path;
		}

		$normalized = rtrim($path, '/\\');

		return $normalized === '' ? $path[0] : $normalized;
	}
}
