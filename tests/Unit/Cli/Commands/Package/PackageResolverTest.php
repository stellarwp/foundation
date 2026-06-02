<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Commands\Package;

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

	public function test_it_throws_when_a_package_cannot_be_resolved(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Could not find a Foundation split package matching "missing".');

		(new PackageResolver($this->data_dir('cli/package/valid-root')))->resolve('missing');
	}
}
