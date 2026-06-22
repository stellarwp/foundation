<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Package;

use RuntimeException;
use StellarWP\Foundation\Cli\Commands\Package\Contracts\PackageRepositoryCreator;
use StellarWP\Foundation\Cli\Process\Contracts\ProcessRunner;
use StellarWP\Foundation\Cli\Process\ShellCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Creates or prepares a Foundation split package and its read-only repository.
 *
 * Run this when adding a new package or preparing an existing package for the
 * monorepo split workflow. Missing packages are scaffolded after confirmation.
 */
final class CreateCommand extends Command
{
	private const string NAME = 'package:create';

	public function __construct(
		private readonly PackageResolver $packageResolver,
		private readonly PackageScaffolder $packageScaffolder,
		private readonly PackageFilesValidator $packageFilesValidator,
		private readonly PackageRepositoryPlanFactory $packageRepositoryPlanFactory,
		private readonly PackageRepositoryCreator $packageRepositoryCreator,
		private readonly ProcessRunner $processRunner
	) {
		parent::__construct(self::NAME);
	}

	protected function configure(): void {
		$this->setDescription('Create and configure a read-only GitHub sub-repository for a Foundation split package.')
			->addArgument('package', InputArgument::REQUIRED, 'Package directory, package short name, or Composer package name.')
			->addOption('apply', null, InputOption::VALUE_NONE, 'Run the generated GitHub actions. Without this option, the command is a dry run.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$packageInput = (string) $input->getArgument('package');

		try {
			$package = $this->packageResolver->resolve($packageInput);
		} catch (RuntimeException) {
			try {
				$package = $this->scaffoldPackage($packageInput, $input, $output);
			} catch (RuntimeException $scaffoldException) {
				$output->writeln('<error>' . $scaffoldException->getMessage() . '</error>');

				return Command::FAILURE;
			}
		}

		$missingFiles = $this->packageFilesValidator->missingFiles($package);

		if ($missingFiles !== []) {
			$output->writeln('<error>The package is missing required split repository files:</error>');

			foreach ($missingFiles as $missingFile) {
				$output->writeln(sprintf(' - %s', $missingFile));
			}

			return Command::FAILURE;
		}

		$plan = $this->packageRepositoryPlanFactory->create($package);

		$output->writeln(sprintf('<info>Package:</info> %s', $package->name));
		$output->writeln(sprintf('<info>Directory:</info> %s', $package->directory));
		$output->writeln(sprintf('<info>Repository:</info> %s', $plan->fullName()));
		$output->writeln(sprintf('<info>Description:</info> %s', $plan->description));

		if (! (bool) $input->getOption('apply')) {
			$output->writeln('');
			$output->writeln('<comment>Dry run. Run with --apply to create/configure the repository.</comment>');

			foreach ($this->packageRepositoryCreator->commands($plan) as $command) {
				$output->writeln(' - ' . ShellCommand::format($command));
			}

			return Command::SUCCESS;
		}

		$this->packageRepositoryCreator->create($plan);

		$output->writeln('<info>Package repository created/configured.</info>');

		return Command::SUCCESS;
	}

	private function scaffoldPackage(string $packageInput, InputInterface $input, OutputInterface $output): Package {
		$defaultPackageName = $this->packageScaffolder->defaultPackageName($packageInput);
		$defaultDirectory   = $this->packageScaffolder->defaultDirectory($packageInput);

		$output->writeln(sprintf('<comment>No existing Foundation split package matched "%s".</comment>', $packageInput));

		if (! $this->questionHelper()->ask(
			$input,
			$output,
			new ConfirmationQuestion(sprintf('Create local package scaffold in %s? [y/N] ', $defaultDirectory), false)
		)) {
			throw new RuntimeException('Package scaffold was not created.');
		}

		$packageName = $this->questionHelper()->ask(
			$input,
			$output,
			new Question(sprintf('Composer package name [%s]: ', $defaultPackageName), $defaultPackageName)
		);

		$scaffold = $this->packageScaffolder->create($packageInput, (string) $packageName);

		$output->writeln(sprintf('<info>Created package scaffold:</info> %s', $scaffold->package->directory));
		$output->writeln(sprintf('<info>Composer package:</info> %s', $scaffold->package->name));

		foreach ($scaffold->createdFiles as $createdFile) {
			$output->writeln(sprintf(' - %s', $createdFile));
		}

		if ($scaffold->createdFiles === []) {
			$output->writeln(sprintf('<comment>No package files were written for %s; all scaffold files already exist.</comment>', $scaffold->package->directory));
		}

		$this->runMonorepoMerge($output);

		return $scaffold->package;
	}

	private function runMonorepoMerge(OutputInterface $output): void {
		$command = ['composer', 'monorepo', 'merge'];

		$output->writeln('');
		$output->writeln(sprintf('<comment>Running %s...</comment>', ShellCommand::format($command)));

		$exitCode = $this->processRunner->run($command);

		if ($exitCode !== 0) {
			throw new RuntimeException(sprintf('Command failed with exit code %d: %s', $exitCode, ShellCommand::format($command)));
		}
	}

	private function questionHelper(): QuestionHelper {
		$helper = $this->getHelper('question');

		if (! $helper instanceof QuestionHelper) {
			throw new RuntimeException('The Symfony question helper is not available.');
		}

		return $helper;
	}
}
