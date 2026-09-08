<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Package;

/**
 * Validates that a split package has the files required for repository creation.
 *
 * Use this before creating the external repository so missing package metadata,
 * read-only warnings, or pull-request closing workflow files are caught early.
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
		'.github/workflows/close-pull-request.yml',
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
