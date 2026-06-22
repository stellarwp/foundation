<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Package;

use RuntimeException;
use StellarWP\Foundation\Cli\Commands\Package\Contracts\PackageRepositoryCreator;
use StellarWP\Foundation\Cli\Process\Contracts\ProcessRunner;
use StellarWP\Foundation\Cli\Process\ShellCommand;

/**
 * GitHub CLI implementation for creating Foundation split repositories.
 *
 * Use this repository creator when `gh` is available locally and the package
 * command should create the repo with the project's standard read-only settings.
 */
final readonly class GitHubPackageRepositoryCreator implements PackageRepositoryCreator
{
	public function __construct(
		private ProcessRunner $processRunner
	) {
	}

	/**
	 * @return list<list<string>>
	 */
	public function commands(PackageRepositoryPlan $plan): array {
		return [
			[
				'gh',
				'repo',
				'create',
				$plan->fullName(),
				'--public',
				'--description',
				$plan->description,
				'--disable-issues',
				'--disable-wiki',
			],
			[
				'gh',
				'repo',
				'edit',
				$plan->fullName(),
				'--enable-projects=false',
			],
			[
				'gh',
				'api',
				'repos/' . $plan->fullName(),
				'--method',
				'PATCH',
				'--field',
				'has_pull_requests=false',
			],
		];
	}

	public function create(PackageRepositoryPlan $plan): void {
		foreach ($this->commands($plan) as $command) {
			$exitCode = $this->processRunner->run($command);

			if ($exitCode !== 0) {
				throw new RuntimeException(sprintf('Command failed with exit code %d: %s', $exitCode, ShellCommand::format($command)));
			}
		}
	}
}
