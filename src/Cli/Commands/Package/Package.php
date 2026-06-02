<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Package;

/**
 * Resolved Foundation split package metadata from the monorepo.
 *
 * This value object represents a package that already exists under `src/` and
 * is ready to be validated or mapped to its external split repository.
 */
final readonly class Package
{
	public function __construct(
		public string $name,
		public string $component,
		public string $directory,
		public string $path,
		public string $composerPath
	) {
	}

	public function repoName(): string {
		return str_replace('stellarwp/', '', $this->name);
	}
}
