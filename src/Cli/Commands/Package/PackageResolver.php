<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Package;

use RuntimeException;

/**
 * Finds Foundation split packages from user-friendly command input.
 *
 * Use this in package commands that accept a directory name, short package name,
 * repository name, or full Composer package name and need the matching package.
 */
final readonly class PackageResolver
{
	public function __construct(
		private string $rootPath
	) {
	}

	public function resolve(string $input): Package {
		$normalizedInput = $this->normalizeInput($input);

		foreach ($this->packages() as $package) {
			if ($this->matches($package, $normalizedInput)) {
				return $package;
			}
		}

		throw new RuntimeException(sprintf('Could not find a Foundation split package matching "%s".', $input));
	}

	/**
	 * @return list<Package>
	 */
	private function packages(): array {
		$composerPaths = glob($this->rootPath . '/src/*/composer.json') ?: [];
		$packages      = [];

		foreach ($composerPaths as $composerPath) {
			$packagePath = dirname($composerPath);
			$composer    = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
			$name        = $composer['name'] ?? '';

			if (! is_string($name) || ! str_starts_with($name, 'stellarwp/foundation-')) {
				continue;
			}

			$packages[] = new Package(
				name: $name,
				component: basename($packagePath),
				directory: 'src/' . basename($packagePath),
				path: $packagePath,
				composerPath: $composerPath
			);
		}

		return $packages;
	}

	private function matches(Package $package, string $input): bool {
		return in_array($input, [
			$this->normalizeInput($package->component),
			$this->normalizeInput($package->name),
			$this->normalizeInput($package->repoName()),
			$this->normalizeInput(str_replace('foundation-', '', $package->repoName())),
		], true);
	}

	private function normalizeInput(string $input): string {
		return strtolower(trim($input));
	}
}
