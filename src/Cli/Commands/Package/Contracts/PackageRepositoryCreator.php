<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Package\Contracts;

use StellarWP\Foundation\Cli\Commands\Package\PackageRepositoryPlan;

/**
 * Creates or configures the external repository for a Foundation split package.
 *
 * Depend on this contract from package commands so repository providers can be
 * replaced without changing package discovery or validation logic.
 */
interface PackageRepositoryCreator
{
	/**
	 * @return list<list<string>>
	 */
	public function commands(PackageRepositoryPlan $plan): array;

	public function create(PackageRepositoryPlan $plan): void;
}
