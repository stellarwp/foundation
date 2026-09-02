<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation;

use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;

/**
 * Resolves project-overridden stubs before package defaults.
 */
final readonly class StubResolver
{
	public function __construct(
		private ProjectDirectory $projectDirectory
	) {
	}

	public function resolve(string $feature, string $stubName, string $defaultPath): string {
		$override = $this->projectDirectory->absolutePath(sprintf(
			'foundation/stubs/%s/%s.stub',
			trim($feature, '/'),
			trim($stubName, '/')
		));

		if (file_exists($override)) {
			return $override;
		}

		return $defaultPath;
	}
}
