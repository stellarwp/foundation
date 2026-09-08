<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Commands\Package;

use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Cli\Commands\Package\PackageResolver;
use StellarWP\Foundation\Tests\TestCase;

final class PackageResolverTest extends TestCase
{
	public function test_it_resolves_packages_by_component_name(): void {
		$package = (new PackageResolver($this->data_dir('cli/package/valid-root')))->resolve('Log');

		$this->assertSame('stellarwp/foundation-log', $package->name);
		$this->assertSame('Log', $package->component);
		$this->assertSame('src/Log', $package->directory);
	}

	public function test_it_resolves_packages_by_repository_name(): void {
		$package = (new PackageResolver($this->data_dir('cli/package/valid-root')))->resolve('foundation-log');

		$this->assertSame('stellarwp/foundation-log', $package->name);
	}

	/**
	 * @dataProvider directoryInputs
	 */
	#[DataProvider('directoryInputs')]
	public function test_it_resolves_package_directories(string $input): void {
		$package = (new PackageResolver($this->data_dir('cli/package/valid-root')))->resolve($input);

		$this->assertSame('stellarwp/foundation-log', $package->name);
	}

	/**
	 * Provide supported directory input forms.
	 *
	 * @return list<array{string}>
	 */
	public static function directoryInputs(): array {
		return [
			['src/Log'],
			['./src/Log/'],
			[' SRC/LOG/ '],
		];
	}

	public function test_it_resolves_an_absolute_package_directory(): void {
		$rootPath = $this->data_dir('cli/package/valid-root');
		$package  = (new PackageResolver($rootPath))->resolve($rootPath . '/src/Log/');

		$this->assertSame('stellarwp/foundation-log', $package->name);
	}

	/**
	 * @dataProvider npmPackageInputs
	 */
	#[DataProvider('npmPackageInputs')]
	public function test_it_resolves_npm_packages(string $input): void {
		$rootPath = dirname(__DIR__, 4) . '/Support/Fixtures/Cli/Package/npm-root';
		$package  = (new PackageResolver($rootPath))->resolve($input);

		$this->assertSame('@stellarwp/foundation-example-package', $package->name);
		$this->assertSame('ExamplePackage', $package->component);
		$this->assertSame('src/ExamplePackage', $package->directory);
		$this->assertSame('foundation-example-package', $package->repoName());
		$this->assertSame($rootPath . '/src/ExamplePackage/package.json', $package->manifestPath);
	}

	/**
	 * Provide the names and directory used to select the ExamplePackage package.
	 *
	 * @return list<array{string}>
	 */
	public static function npmPackageInputs(): array {
		return [
			['ExamplePackage'],
			['src/ExamplePackage'],
			['foundation-example-package'],
			['@stellarwp/foundation-example-package'],
		];
	}

	public function test_composer_owns_a_package_with_both_manifests(): void {
		$rootPath = dirname(__DIR__, 4) . '/Support/Fixtures/Cli/Package/mixed-root';
		$package  = (new PackageResolver($rootPath))->resolve('src/ExamplePackage');

		$this->assertSame('stellarwp/foundation-example-package', $package->name);
		$this->assertSame($rootPath . '/src/ExamplePackage/composer.json', $package->manifestPath);
	}

	public function test_an_npm_name_cannot_select_a_second_package_in_a_composer_directory(): void {
		$rootPath = dirname(__DIR__, 4) . '/Support/Fixtures/Cli/Package/mixed-root';

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Could not find a Foundation split package matching "@stellarwp/foundation-example-assets".');

		(new PackageResolver($rootPath))->resolve('@stellarwp/foundation-example-assets');
	}

	public function test_composer_packages_do_not_parse_their_frontend_manifest(): void {
		$rootPath = $this->prepare_temp_dir('package-resolver-mixed');
		mkdir($rootPath . '/src/ExamplePackage', 0777, true);
		file_put_contents($rootPath . '/src/ExamplePackage/composer.json', '{"name":"stellarwp/foundation-example-package"}');
		file_put_contents($rootPath . '/src/ExamplePackage/package.json', 'invalid JSON');

		$package = (new PackageResolver($rootPath))->resolve('ExamplePackage');

		$this->assertSame('stellarwp/foundation-example-package', $package->name);
	}

	public function test_it_throws_when_a_package_cannot_be_resolved(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Could not find a Foundation split package matching "missing".');

		(new PackageResolver($this->data_dir('cli/package/valid-root')))->resolve('missing');
	}
}
