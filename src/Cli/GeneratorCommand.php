<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli;

use RuntimeException;
use StellarWP\Foundation\Cli\Generation\ClassGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates a PHP class from a project stub without overwriting existing files.
 *
 * Define NAME, CONFIG_KEY, DEFAULT_NAMESPACE, and stub() in the application command.
 * The default namespace is relative to the project's first Composer runtime namespace.
 */
abstract class GeneratorCommand extends Command
{
	public const string NAME              = '';
	public const string CONFIG_KEY        = '';
	public const string DEFAULT_NAMESPACE = '';

	/**
	 * Receive Foundation's generation service through inherited construction.
	 *
	 * @throws RuntimeException When the command does not define its name and configuration key.
	 */
	final public function __construct(private readonly ClassGenerator $generator) {
		if (trim(static::NAME) === '' || trim(static::CONFIG_KEY) === '') {
			throw new RuntimeException(static::class . ' must define NAME and CONFIG_KEY.');
		}

		parent::__construct(static::NAME);
	}

	/**
	 * Return the project-relative or absolute stub path containing {{ namespace }} and {{ class }}.
	 */
	abstract protected function stub(): string;

	/**
	 * Define the standard single-class generation options.
	 */
	final protected function configure(): void {
		$this->setDescription('Generate a ' . static::CONFIG_KEY . ' class.')
			->addArgument('name', InputArgument::REQUIRED, 'Class name, e.g. Sales_Report.')
			->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace for the generated class.')
			->addOption('path', null, InputOption::VALUE_REQUIRED, 'Output directory for the generated class.');
	}

	/**
	 * Generate the class and report its location or the creation failure.
	 */
	final protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$file = $this->generator->generate(
				(string) $input->getArgument('name'),
				static::CONFIG_KEY,
				static::DEFAULT_NAMESPACE,
				$this->stub(),
				(string) $input->getOption('namespace'),
				(string) $input->getOption('path')
			);
		} catch (RuntimeException $exception) {
			$output->writeln('<error>' . $exception->getMessage() . '</error>');

			return Command::FAILURE;
		}

		$output->writeln('<info>Created:</info> ' . $file->relativePath);

		return Command::SUCCESS;
	}
}
