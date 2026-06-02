<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Commands\Package;

use RuntimeException;
use StellarWP\Foundation\Cli\Commands\Package\GitHubPackageRepositoryCreator;
use StellarWP\Foundation\Cli\Commands\Package\PackageRepositoryPlan;
use StellarWP\Foundation\Cli\Process\Contracts\ProcessRunner;
use StellarWP\Foundation\Tests\TestCase;

final class GitHubPackageRepositoryCreatorTest extends TestCase
{
	public function test_it_builds_github_cli_commands(): void {
		$creator = new GitHubPackageRepositoryCreator(new TestShellProcessRunner());

		$this->assertSame([
			[
				'gh',
				'repo',
				'create',
				'stellarwp/foundation-log',
				'--public',
				'--description',
				'[READ ONLY] Subtree split of the Foundation Log component (see stellarwp/foundation)',
				'--disable-issues',
				'--disable-wiki',
			],
			[
				'gh',
				'repo',
				'edit',
				'stellarwp/foundation-log',
				'--enable-projects=false',
			],
		], $creator->commands($this->plan()));
	}

	public function test_it_runs_github_cli_commands(): void {
		$processRunner = new TestShellProcessRunner();
		$creator       = new GitHubPackageRepositoryCreator($processRunner);

		$creator->create($this->plan());

		$this->assertCount(2, $processRunner->commands);
	}

	public function test_it_throws_when_a_github_cli_command_fails(): void {
		$creator = new GitHubPackageRepositoryCreator(new TestShellProcessRunner(exitCode: 1));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Command failed with exit code 1');

		$creator->create($this->plan());
	}

	private function plan(): PackageRepositoryPlan {
		return new PackageRepositoryPlan(
			organization: 'stellarwp',
			repository: 'foundation-log',
			description: '[READ ONLY] Subtree split of the Foundation Log component (see stellarwp/foundation)'
		);
	}
}

final class TestShellProcessRunner implements ProcessRunner
{
	/**
	 * @var list<list<string>>
	 */
	public array $commands = [];

	public function __construct(
		private readonly int $exitCode = 0
	) {
	}

	/**
	 * @param list<string> $command
	 */
	public function run(array $command): int {
		$this->commands[] = $command;

		return $this->exitCode;
	}
}
