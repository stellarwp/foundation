<?php declare(strict_types=1);

namespace Plugin\Cli\Commands\Make\Report;

use RuntimeException;
use StellarWP\Foundation\Cli\Generation\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
use StellarWP\Foundation\Cli\Generation\GeneratorLocationResolver;
use StellarWP\Foundation\Cli\Generation\StubRenderer;
use StellarWP\Foundation\Cli\Generation\ValueObjects\GeneratedFile;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use StellarWP\Foundation\Cli\Generation\WordPressClassNameResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate report classes using the project's stub and namespace settings.
 */
final class Report_Command extends Command
{
	public const string CONFIG_KEY = 'report';
	public const string NAME       = 'make:' . self::CONFIG_KEY;

	/**
	 * Receive the services used to render and write report classes.
	 */
	public function __construct(
		private readonly ProjectDirectory $project_directory,
		private readonly ComposerAutoloadResolver $autoload,
		private readonly GeneratorLocationResolver $locations,
		private readonly WordPressClassNameResolver $class_names,
		private readonly StubRenderer $stubs,
		private readonly GeneratedFileWriter $files
	) {
		parent::__construct(self::NAME);
	}

	protected function configure(): void {
		$this->setDescription('Generate a report class.')
			->addArgument('name', InputArgument::REQUIRED, 'Report class name, e.g. Sales_Report.')
			->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace for the report.')
			->addOption('path', null, InputOption::VALUE_REQUIRED, 'Output directory for the report.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$file = $this->generated_file($input);
			$this->files->write($file);
		} catch (RuntimeException $exception) {
			$output->writeln('<error>' . $exception->getMessage() . '</error>');

			return Command::FAILURE;
		}

		$output->writeln('<info>Created:</info> ' . $file->relativePath);

		return Command::SUCCESS;
	}

	/**
	 * Render the report at its configured or explicitly selected location.
	 *
	 * @throws RuntimeException When the project, class name, namespace, or stub is invalid.
	 */
	private function generated_file(InputInterface $input): GeneratedFile {
		$project   = $this->autoload->project();
		$class     = $this->class_names->className((string) $input->getArgument('name'));
		$namespace = $this->locations->namespaceFor(self::CONFIG_KEY, $project, (string) $input->getOption('namespace'));
		$directory = $this->locations->directoryFor($namespace, $project, (string) $input->getOption('path'));
		$path      = $directory . '/' . $class . '.php';
		$stub      = $this->project_directory->absolutePath('foundation/stubs/report/report.stub');

		return new GeneratedFile(
			path: $path,
			relativePath: $this->project_directory->relativePath($path),
			contents: $this->stubs->render($stub, [
				'namespace' => $namespace,
				'class'     => $class,
			])
		);
	}
}
