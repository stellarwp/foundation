<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Package;

/**
 * Builds the standard GitHub repository plan for a Foundation split package.
 *
 * Keep repository naming and read-only description policy here so package
 * commands and repository creators do not duplicate that release convention.
 */
final class PackageRepositoryPlanFactory
{
	private const string ORGANIZATION = 'stellarwp';

	public function create(Package $package): PackageRepositoryPlan {
		return new PackageRepositoryPlan(
			organization: self::ORGANIZATION,
			repository: $package->repoName(),
			description: sprintf(
				'[READ ONLY] Subtree split of the Foundation %s component (see stellarwp/foundation)',
				$package->component
			)
		);
	}
}
