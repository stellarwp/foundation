<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use StellarWP\Foundation\Database\Migration\Contracts\Migration;
use StellarWP\Foundation\Database\Migration\ValueObjects\MigrationDirectory;
use StellarWP\Foundation\Database\Migration\ValueObjects\MigrationRegistration;

/**
 * Load historical migration declarations from the configured directory.
 *
 * @internal
 */
final readonly class MigrationDiscovery
{
	/**
	 * Receive the application root and optional migration directory override.
	 */
	public function __construct(
		private ?string $root,
		private ?string $path,
	) {
	}

	/**
	 * Load timestamped migration files, including feature subfolders.
	 *
	 * @throws InvalidArgumentException When an explicitly configured directory is missing.
	 * @throws \TypeError               When a file does not return a migration.
	 *
	 * @return iterable<MigrationRegistration>
	 */
	public function migrations(): iterable {
		if ($this->root === null && $this->path === null) {
			return;
		}

		$directory = new MigrationDirectory($this->root, $this->path ?? MigrationDirectory::DEFAULT_PATH);

		if (! file_exists($directory->path)) {
			if ($this->path !== null) {
				throw new InvalidArgumentException('Configured migration directory does not exist: ' . $directory->path);
			}

			return;
		}

		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory->path, FilesystemIterator::SKIP_DOTS));

		/** @var SplFileInfo $file */
		foreach ($files as $file) {
			if (! $file->isFile() || preg_match('/^[0-9]{14}_[a-z0-9_]+\.php$/', $file->getFilename()) !== 1) {
				continue;
			}

			yield new MigrationRegistration($file->getBasename('.php'), self::load($file->getPathname()));
		}
	}

	/**
	 * Load a declaration without exposing discovery's instance to the migration file.
	 */
	private static function load(string $file): Migration {
		return require $file;
	}
}
