<?php declare(strict_types=1);

namespace StellarWP\Foundation\Dev\Cli\Commands\Package;

/**
 * Validates that a split package has the files required for repository creation.
 *
 * Use this before creating the external repository to identify missing package
 * manifests, README files, and Git configuration files.
 */
final class PackageFilesValidator
{
	/**
	 * @var list<string>
	 */
	private const array REQUIRED_FILES = [
		'README.md',
		'.gitattributes',
		'.gitignore',
	];

	/**
	 * List the missing manifest and shared split repository files.
	 *
	 * @return list<string>
	 */
	public function missingFiles(Package $package): array {
		$missingFiles = [];

		foreach ([basename($package->manifestPath), ...self::REQUIRED_FILES] as $requiredFile) {
			if (! file_exists($package->path . '/' . $requiredFile)) {
				$missingFiles[] = $requiredFile;
			}
		}

		return $missingFiles;
	}
}
