<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation;

/**
 * Resolves project-overridden stubs before package defaults.
 */
final readonly class StubResolver
{
	public function __construct(
		private string $rootPath
	) {
	}

	public function resolve(string $feature, string $stubName, string $defaultPath): string {
		$override = sprintf('%s/foundation/stubs/%s/%s.stub', $this->rootPath, trim($feature, '/'), trim($stubName, '/'));

		if (file_exists($override)) {
			return $override;
		}

		return $defaultPath;
	}
}
