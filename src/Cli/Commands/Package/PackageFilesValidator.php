<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Package;

/**
 * Validates that a split package has the files required for repository creation.
 *
 * Check required package files before attempting to create the external repository.
 */
final class PackageFilesValidator
{
	/**
	 * @var list<string>
	 */
	private const array REQUIRED_FILES = [
		'composer.json',
		'README.md',
		'.gitattributes',
		'.gitignore',
	];

	/**
	 * @return list<string>
	 */
	public function missingFiles(Package $package): array {
		$missingFiles = [];

		foreach (self::REQUIRED_FILES as $requiredFile) {
			if (! file_exists($package->path . '/' . $requiredFile)) {
				$missingFiles[] = $requiredFile;
			}
		}

		return $missingFiles;
	}
}
