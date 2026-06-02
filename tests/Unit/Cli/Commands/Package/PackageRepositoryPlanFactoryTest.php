<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Commands\Package;

use StellarWP\Foundation\Cli\Commands\Package\Package;
use StellarWP\Foundation\Cli\Commands\Package\PackageRepositoryPlanFactory;
use StellarWP\Foundation\Tests\TestCase;

final class PackageRepositoryPlanFactoryTest extends TestCase
{
	public function test_it_creates_a_package_repository_plan_from_a_package(): void {
		$plan = (new PackageRepositoryPlanFactory())->create(new Package(
			name: 'stellarwp/foundation-log',
			component: 'Log',
			directory: 'src/Log',
			path: '/repo/src/Log',
			composerPath: '/repo/src/Log/composer.json'
		));

		$this->assertSame('stellarwp', $plan->organization);
		$this->assertSame('foundation-log', $plan->repository);
		$this->assertSame('stellarwp/foundation-log', $plan->fullName());
		$this->assertSame('[READ ONLY] Subtree split of the Foundation Log component (see stellarwp/foundation)', $plan->description);
	}
}
