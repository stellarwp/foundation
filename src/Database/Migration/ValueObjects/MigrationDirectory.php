<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\ValueObjects;

use InvalidArgumentException;

/**
 * The configured location of application migration files.
 *
 * @internal
 */
final readonly class MigrationDirectory
{
	public const string DEFAULT_PATH = 'db/migrations';

	public string $path;

	/**
	 * Resolve a configured location against the application's explicitly supplied root.
	 *
	 * @throws InvalidArgumentException When a relative location has no absolute application root.
	 */
	public function __construct(
		?string $root,
		string $path = self::DEFAULT_PATH,
	) {
		if (trim($path) === '') {
			throw new InvalidArgumentException('database.migrations.path cannot be blank.');
		}

		if (! self::isAbsolute($path)) {
			if ($root === null || ! self::isAbsolute($root)) {
				throw new InvalidArgumentException('Set foundation.root to the absolute project directory in config.php before discovering migrations.');
			}

			$path = $root . '/' . $path;
		}

		$this->path = self::normalize($path);
	}

	/**
	 * Normalize separators and dot segments in the configured path.
	 */
	private static function normalize(string $path): string {
		$path  = str_replace('\\', '/', $path);
		$parts = [];

		foreach (explode('/', $path) as $part) {
			if ($part === '' || $part === '.') {
				continue;
			}

			if ($part === '..') {
				array_pop($parts);
				continue;
			}

			$parts[] = $part;
		}

		return (str_starts_with($path, '/') ? '/' : '') . implode('/', $parts);
	}

	private static function isAbsolute(string $path): bool {
		return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
	}
}
