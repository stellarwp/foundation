<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation;

use RuntimeException;

/**
 * Reads a project's Composer autoload configuration for generator defaults.
 *
 * Make commands use this to infer where application classes should be written
 * and which namespace they should use when the developer does not pass options.
 */
final readonly class ComposerAutoloadResolver
{
	public function __construct(
		private string $rootPath
	) {
	}

	public function firstPsr4Namespace(): AutoloadNamespace {
		$composerPath = $this->rootPath . '/composer.json';

		if (! file_exists($composerPath)) {
			throw new RuntimeException(sprintf('Could not find composer.json at "%s".', $composerPath));
		}

		$composer = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
		$psr4     = $composer['autoload']['psr-4'] ?? [];

		if (! is_array($psr4) || $psr4 === []) {
			throw new RuntimeException('Could not find an autoload.psr-4 namespace in composer.json.');
		}

		foreach ($psr4 as $namespace => $paths) {
			if (! is_string($namespace)) {
				continue;
			}

			$path = is_array($paths) ? reset($paths) : $paths;

			if (! is_string($path) || $path === '') {
				continue;
			}

			return new AutoloadNamespace(
				namespace: trim($namespace, '\\') . '\\',
				path: trim($path, '/')
			);
		}

		throw new RuntimeException('Could not find a valid autoload.psr-4 namespace in composer.json.');
	}
}
