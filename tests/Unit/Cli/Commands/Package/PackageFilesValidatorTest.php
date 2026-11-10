<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Commands\Package;

use StellarWP\Foundation\Dev\Cli\Commands\Package\Package;
use StellarWP\Foundation\Dev\Cli\Commands\Package\PackageFilesValidator;
use StellarWP\Foundation\Tests\TestCase;

final class PackageFilesValidatorTest extends TestCase
{
	public function test_it_reports_missing_required_package_files(): void {
		$path = $this->data_dir('cli/package/missing-files-root/src/Log');

		$missingFiles = (new PackageFilesValidator())->missingFiles(new Package(
			name: 'stellarwp/foundation-log',
			component: 'Log',
			directory: 'src/Log',
			path: $path,
			manifestPath: $path . '/composer.json'
		));

		$this->assertSame([
			'README.md',
			'.gitattributes',
			'.gitignore',
		], $missingFiles);
	}
}
