<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Package;

/**
 * Repository creation plan derived from a resolved Foundation package.
 *
 * This value object carries the GitHub organization, repository name, and
 * description that repository creators should apply.
 */
final readonly class PackageRepositoryPlan
{
	public function __construct(
		public string $organization,
		public string $repository,
		public string $description
	) {
	}

	public function fullName(): string {
		return $this->organization . '/' . $this->repository;
	}
}
