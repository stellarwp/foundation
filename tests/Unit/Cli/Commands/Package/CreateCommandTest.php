<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Commands\Package;

use StellarWP\Foundation\Cli\Commands\Package\Contracts\PackageRepositoryCreator;
use StellarWP\Foundation\Cli\Commands\Package\CreateCommand;
use StellarWP\Foundation\Cli\Commands\Package\PackageFilesValidator;
use StellarWP\Foundation\Cli\Commands\Package\PackageRepositoryPlan;
use StellarWP\Foundation\Cli\Commands\Package\PackageRepositoryPlanFactory;
use StellarWP\Foundation\Cli\Commands\Package\PackageResolver;
use StellarWP\Foundation\Cli\Commands\Package\PackageScaffolder;
use StellarWP\Foundation\Cli\Process\Contracts\ProcessRunner;
use StellarWP\Foundation\Tests\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateCommandTest extends TestCase
{
	/**
	 * @var list<string>
	 */
	private array $temporaryRoots = [];

	private string $tempDir;

	protected function setUp(): void {
		parent::setUp();

		$this->tempDir = $this->prepare_temp_dir('package-create-command');
	}

	protected function tearDown(): void {
		foreach ($this->temporaryRoots as $temporaryRoot) {
			$this->removeDirectory($temporaryRoot);
		}

		parent::tearDown();
	}

	public function test_it_outputs_a_dry_run_by_default(): void {
		$rootPath                 = $this->data_dir('cli/package/valid-root');
		$packageRepositoryCreator = new FakePackageRepositoryCreator();

		$command = new CreateCommand(
			new PackageResolver($rootPath),
			new PackageScaffolder($rootPath),
			new PackageFilesValidator(),
			new PackageRepositoryPlanFactory(),
			$packageRepositoryCreator,
			new FakeProcessRunner()
		);

		$tester     = new CommandTester($command);
		$statusCode = $tester->execute(['package' => 'Log']);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertStringContainsString('Package: stellarwp/foundation-log', $tester->getDisplay());
		$this->assertStringContainsString('Dry run. Run with --apply', $tester->getDisplay());
		$this->assertStringContainsString("'gh' 'repo' 'create'", $tester->getDisplay());
		$this->assertFalse($packageRepositoryCreator->created);
	}

	public function test_it_creates_the_package_repository_when_apply_is_passed(): void {
		$packageRepositoryCreator = new FakePackageRepositoryCreator();
		$command                  = new CreateCommand(
			new PackageResolver($this->data_dir('cli/package/valid-root')),
			new PackageScaffolder($this->data_dir('cli/package/valid-root')),
			new PackageFilesValidator(),
			new PackageRepositoryPlanFactory(),
			$packageRepositoryCreator,
			new FakeProcessRunner()
		);

		$tester     = new CommandTester($command);
		$statusCode = $tester->execute([
			'package' => 'Log',
			'--apply' => true,
		]);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertTrue($packageRepositoryCreator->created);
		$this->assertStringContainsString('Package repository created/configured.', $tester->getDisplay());
	}

	public function test_it_fails_when_the_package_cannot_be_resolved(): void {
		$command = new CreateCommand(
			new PackageResolver($this->data_dir('cli/package/valid-root')),
			new PackageScaffolder($this->data_dir('cli/package/valid-root')),
			new PackageFilesValidator(),
			new PackageRepositoryPlanFactory(),
			new FakePackageRepositoryCreator(),
			new FakeProcessRunner()
		);
		$command->setHelperSet($this->questionHelperSet());

		$tester     = new CommandTester($command);
		$statusCode = $tester->execute(['package' => 'missing']);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('No existing Foundation split package matched "missing".', $tester->getDisplay());
		$this->assertStringContainsString('Package scaffold was not created.', $tester->getDisplay());
	}

	public function test_it_prompts_to_scaffold_a_missing_package_before_creating_the_repository(): void {
		$rootPath                 = $this->temporaryRoot();
		$packageRepositoryCreator = new FakePackageRepositoryCreator();
		$processRunner            = new FakeProcessRunner();
		$command                  = new CreateCommand(
			new PackageResolver($rootPath),
			new PackageScaffolder($rootPath),
			new PackageFilesValidator(),
			new PackageRepositoryPlanFactory(),
			$packageRepositoryCreator,
			$processRunner
		);
		$command->setHelperSet($this->questionHelperSet());

		$tester = new CommandTester($command);
		$tester->setInputs(['yes', '']);
		$statusCode = $tester->execute(['package' => 'WPCli']);

		$this->assertSame(Command::SUCCESS, $statusCode);
		$this->assertFalse($packageRepositoryCreator->created);
		$this->assertStringContainsString('No existing Foundation split package matched "WPCli".', $tester->getDisplay());
		$this->assertStringContainsString('Created package scaffold: src/WPCli', $tester->getDisplay());
		$this->assertStringContainsString('Composer package: stellarwp/foundation-wpcli', $tester->getDisplay());
		$this->assertStringContainsString("Running 'composer' 'monorepo' 'merge'...", $tester->getDisplay());
		$this->assertStringContainsString('Repository: stellarwp/foundation-wpcli', $tester->getDisplay());
		$this->assertSame([
			['composer', 'monorepo', 'merge'],
		], $processRunner->commands);
		$this->assertFileExists($rootPath . '/src/WPCli/composer.json');
		$this->assertFileExists($rootPath . '/src/WPCli/.github/workflows/close-pull-request.yml');
		$this->assertStringContainsString('"name": "stellarwp/foundation-wpcli"', (string) file_get_contents($rootPath . '/src/WPCli/composer.json'));
	}

	public function test_it_fails_when_monorepo_merge_fails_after_scaffolding(): void {
		$rootPath                 = $this->temporaryRoot();
		$packageRepositoryCreator = new FakePackageRepositoryCreator();
		$command                  = new CreateCommand(
			new PackageResolver($rootPath),
			new PackageScaffolder($rootPath),
			new PackageFilesValidator(),
			new PackageRepositoryPlanFactory(),
			$packageRepositoryCreator,
			new FakeProcessRunner(exitCode: 1)
		);
		$command->setHelperSet($this->questionHelperSet());

		$tester = new CommandTester($command);
		$tester->setInputs(['yes', '']);
		$statusCode = $tester->execute(['package' => 'WPCli']);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertFalse($packageRepositoryCreator->created);
		$this->assertStringContainsString("Command failed with exit code 1: 'composer' 'monorepo' 'merge'", $tester->getDisplay());
	}

	public function test_it_fails_when_required_split_package_files_are_missing(): void {
		$command = new CreateCommand(
			new PackageResolver($this->data_dir('cli/package/missing-files-root')),
			new PackageScaffolder($this->data_dir('cli/package/missing-files-root')),
			new PackageFilesValidator(),
			new PackageRepositoryPlanFactory(),
			new FakePackageRepositoryCreator(),
			new FakeProcessRunner()
		);

		$tester     = new CommandTester($command);
		$statusCode = $tester->execute(['package' => 'Log']);

		$this->assertSame(Command::FAILURE, $statusCode);
		$this->assertStringContainsString('The package is missing required split repository files:', $tester->getDisplay());
		$this->assertStringContainsString('README.md', $tester->getDisplay());
	}

	private function temporaryRoot(): string {
		$root = $this->tempDir . '/foundation-cli-test-' . bin2hex(random_bytes(8));

		if (! mkdir($root, 0777, true) && ! is_dir($root)) {
			$this->fail(sprintf('Could not create temporary root "%s".', $root));
		}

		$this->temporaryRoots[] = $root;

		return $root;
	}

	private function questionHelperSet(): HelperSet {
		return new HelperSet([
			'question' => new QuestionHelper(),
		]);
	}

	private function removeDirectory(string $directory): void {
		if (! is_dir($directory)) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($files as $file) {
			if ($file->isDir()) {
				rmdir($file->getPathname());
			} else {
				unlink($file->getPathname());
			}
		}

		rmdir($directory);
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

final class FakeProcessRunner implements ProcessRunner
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
