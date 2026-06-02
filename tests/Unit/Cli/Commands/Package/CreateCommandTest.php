<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Commands\Package;

use StellarWP\Foundation\Cli\Commands\Package\Contracts\PackageRepositoryCreator;
use StellarWP\Foundation\Cli\Commands\Package\CreateCommand;
use StellarWP\Foundation\Cli\Commands\Package\PackageFilesValidator;
use StellarWP\Foundation\Cli\Commands\Package\PackageRepositoryPlan;
use StellarWP\Foundation\Cli\Commands\Package\PackageRepositoryPlanFactory;
use StellarWP\Foundation\Cli\Commands\Package\PackageResolver;
use StellarWP\Foundation\Tests\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateCommandTest extends TestCase
{
	public function test_it_outputs_a_dry_run_by_default(): void {
		$rootPath                 = $this->data_dir('cli/package/valid-root');
		$packageRepositoryCreator = new FakePackageRepositoryCreator();

		$command = new CreateCommand(
			new PackageResolver($rootPath),
			new PackageFilesValidator(),
			new PackageRepositoryPlanFactory(),
			$packageRepositoryCreator
		);

		$tester     = new CommandTester($command);
		$statusCode = $tester->execute(['package' => 'Log']);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('Package: stellarwp/foundation-log', $tester->getDisplay());
		$this->assertStringContainsString('Dry run. Run with --apply', $tester->getDisplay());
		$this->assertStringContainsString("'gh' 'repo' 'create'", $tester->getDisplay());
		$this->assertStringContainsString('Manual step: GitHub CLI cannot disable pull requests.', $tester->getDisplay());
		$this->assertFalse($packageRepositoryCreator->created);
	}

	public function test_it_creates_the_package_repository_when_apply_is_passed(): void {
		$packageRepositoryCreator = new FakePackageRepositoryCreator();
		$command                  = new CreateCommand(
			new PackageResolver($this->data_dir('cli/package/valid-root')),
			new PackageFilesValidator(),
			new PackageRepositoryPlanFactory(),
			$packageRepositoryCreator
		);

		$tester     = new CommandTester($command);
		$statusCode = $tester->execute([
			'package' => 'Log',
			'--apply' => true,
		]);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertTrue($packageRepositoryCreator->created);
		$this->assertStringContainsString('Package repository created/configured.', $tester->getDisplay());
		$this->assertStringContainsString('Manual step: GitHub CLI cannot disable pull requests.', $tester->getDisplay());
	}

	public function test_it_fails_when_the_package_cannot_be_resolved(): void {
		$command = new CreateCommand(
			new PackageResolver($this->data_dir('cli/package/valid-root')),
			new PackageFilesValidator(),
			new PackageRepositoryPlanFactory(),
			new FakePackageRepositoryCreator()
		);

		$tester     = new CommandTester($command);
		$statusCode = $tester->execute(['package' => 'missing']);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('Could not find a Foundation split package matching "missing".', $tester->getDisplay());
	}

	public function test_it_fails_when_required_split_package_files_are_missing(): void {
		$command = new CreateCommand(
			new PackageResolver($this->data_dir('cli/package/missing-files-root')),
			new PackageFilesValidator(),
			new PackageRepositoryPlanFactory(),
			new FakePackageRepositoryCreator()
		);

		$tester     = new CommandTester($command);
		$statusCode = $tester->execute(['package' => 'Log']);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('The package is missing required split repository files:', $tester->getDisplay());
		$this->assertStringContainsString('README.md', $tester->getDisplay());
	}
}

final class FakePackageRepositoryCreator implements PackageRepositoryCreator
{
	public bool $created = false;

	/**
	 * @return list<list<string>>
	 */
	public function commands(PackageRepositoryPlan $plan): array {
		return [
			['gh', 'repo', 'create', $plan->fullName()],
		];
	}

	public function create(PackageRepositoryPlan $plan): void {
		$this->created = true;
	}
}
