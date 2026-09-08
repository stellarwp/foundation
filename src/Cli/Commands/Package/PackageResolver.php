<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Package;

use JsonException;
use RuntimeException;

/**
 * Finds Foundation split packages from user-friendly command input.
 *
 * Use this in package commands that accept a directory name, short package name,
 * repository name, or full Composer or npm package name and need the matching package.
 */
final readonly class PackageResolver
{
	public function __construct(
		private string $rootPath
	) {
	}

	/**
	 * Resolve an existing split package by its name or directory.
	 *
	 * @throws RuntimeException When no Foundation package matches the input.
	 * @throws JsonException    When a package manifest contains invalid JSON.
	 */
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
		$manifestPaths = glob($this->rootPath . '/src/*/{composer,package}.json', GLOB_BRACE) ?: [];
		$packages      = [];

		foreach ($manifestPaths as $manifestPath) {
			$packagePath = dirname($manifestPath);

			// A Composer package may also have an npm manifest for frontend tooling.
			if (basename($manifestPath) === 'package.json' && is_file($packagePath . '/composer.json')) {
				continue;
			}

			$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
			$name     = $manifest['name'] ?? '';

			if (! is_string($name) || ! preg_match('#^@?stellarwp/foundation-#', $name)) {
				continue;
			}

			$packages[] = new Package(
				name: $name,
				component: basename($packagePath),
				directory: 'src/' . basename($packagePath),
				path: $packagePath,
				manifestPath: $manifestPath
			);
		}

		return $packages;
	}

	private function matches(Package $package, string $input): bool {
		return in_array($input, [
			$this->normalizeInput($package->directory),
			$this->normalizeInput('./' . $package->directory),
			$this->normalizeInput($package->path),
			$this->normalizeInput($package->component),
			$this->normalizeInput($package->name),
			$this->normalizeInput($package->repoName()),
			$this->normalizeInput(str_replace('foundation-', '', $package->repoName())),
		], true);
	}

	private function normalizeInput(string $input): string {
		return strtolower(rtrim(trim($input), '/'));
	}
}
