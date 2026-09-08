<?php declare(strict_types=1);

namespace StellarWP\Foundation\Dev\Cli;

use StellarWP\Foundation\Cli\CliProvider;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\Dev\Cli\Commands\Package\Contracts\PackageRepositoryCreator;
use StellarWP\Foundation\Dev\Cli\Commands\Package\CreateCommand;
use StellarWP\Foundation\Dev\Cli\Commands\Package\GitHubPackageRepositoryCreator;
use StellarWP\Foundation\Dev\Cli\Commands\Package\PackageFilesValidator;
use StellarWP\Foundation\Dev\Cli\Commands\Package\PackageRepositoryPlanFactory;
use StellarWP\Foundation\Dev\Cli\Commands\Package\PackageResolver;
use StellarWP\Foundation\Dev\Cli\Commands\Package\PackageScaffolder;

/**
 * Registers Foundation monorepo maintenance commands.
 */
final class MonorepoProvider extends Provider
{
	private bool $registered = false;

	/**
	 * Wire repository maintenance and contribute its commands once.
	 */
	public function register(): void {
		if ($this->registered) {
			return;
		}

		$this->registerPackageCommand();
		$this->registered = true;
	}

	/**
	 * Register the split-package creation command and its collaborators.
	 */
	private function registerPackageCommand(): void {
		$this->container->when(PackageResolver::class)
			->needs('$rootPath')
			->give(static fn (C $c): string => $c->get(ProjectDirectory::class)->path);

		$this->container->when(PackageScaffolder::class)
			->needs('$rootPath')
			->give(static fn (C $c): string => $c->get(ProjectDirectory::class)->path);

		$this->container->singleton(PackageResolver::class);
		$this->container->singleton(PackageScaffolder::class);
		$this->container->singleton(PackageFilesValidator::class);
		$this->container->singleton(PackageRepositoryPlanFactory::class);
		$this->container->bind(PackageRepositoryCreator::class, GitHubPackageRepositoryCreator::class);
		$this->container->singleton(CreateCommand::class);
		$this->container->mergeArrayVar(CliProvider::COMMANDS, static fn (C $c): array => [$c->get(CreateCommand::class)]);
	}
}
