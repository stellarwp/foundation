<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Package;

use RuntimeException;
use StellarWP\Foundation\Cli\Commands\Package\Contracts\PackageRepositoryCreator;
use StellarWP\Foundation\Cli\Process\ShellCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates the read-only GitHub repository for an existing Foundation package.
 *
 * Run this after adding the package files in `src/<Package>` and before relying
 * on the monorepo split workflow to publish that package to GitHub.
 */
final class CreateCommand extends Command
{
	private const string NAME              = 'package:create';
	private const string PULL_REQUEST_NOTE = 'Manual step: GitHub CLI cannot disable pull requests. Confirm pull requests are disabled or restricted in GitHub settings; otherwise keep close-pull-request.yml in place to close incoming pull requests.';

	public function __construct(
		private readonly PackageResolver $packageResolver,
		private readonly PackageFilesValidator $packageFilesValidator,
		private readonly PackageRepositoryPlanFactory $packageRepositoryPlanFactory,
		private readonly PackageRepositoryCreator $packageRepositoryCreator
	) {
		parent::__construct(self::NAME);
	}

	protected function configure(): void {
		$this->setDescription('Create and configure a read-only GitHub sub-repository for a Foundation split package.')
			->addArgument('package', InputArgument::REQUIRED, 'Package directory, package short name, or Composer package name.')
			->addOption('apply', null, InputOption::VALUE_NONE, 'Run the generated GitHub actions. Without this option, the command is a dry run.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$package = $this->packageResolver->resolve((string) $input->getArgument('package'));
		} catch (RuntimeException $exception) {
			$output->writeln('<error>' . $exception->getMessage() . '</error>');

			return Command::FAILURE;
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

			$output->writeln('');
			$output->writeln('<comment>' . self::PULL_REQUEST_NOTE . '</comment>');

			return Command::SUCCESS;
		}

		$this->packageRepositoryCreator->create($plan);

		$output->writeln('<info>Package repository created/configured.</info>');
		$output->writeln('<comment>' . self::PULL_REQUEST_NOTE . '</comment>');

		return Command::SUCCESS;
	}
}
