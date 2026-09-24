<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Make\Database;

use InvalidArgumentException;
use RuntimeException;
use StellarWP\Foundation\Cli\Commands\Make\Database\Factories\MigrationFileFactory;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
use StellarWP\Foundation\Cli\Generation\ValueObjects\GeneratedFile;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate an anonymous migration file for Foundation Migrations.
 *
 * Use this from a consuming WordPress project when a feature needs a versioned
 * database change discovered from the configured migration directory.
 */
final class MigrationCommand extends Command
{
	public const string NAME = 'make:database:migration';

	/**
	 * Create the migration generator for a consuming project root.
	 */
	public function __construct(
		private readonly ProjectDirectory $projectDirectory,
		private readonly MigrationFileFactory $migrationFactory,
		private readonly GeneratedFileWriter $fileWriter
	) {
		parent::__construct(self::NAME);
	}

	/**
	 * Define the migration generation modes and project customization options.
	 */
	protected function configure(): void {
		$this->setDescription('Create a new Foundation database migration.')
			->setHelp('Use --create for a new table, --table for explicit changes to an existing table, or neither for a generic migration. The table options are mutually exclusive and accept stable, unprefixed table names.')
			->addArgument('name', InputArgument::REQUIRED, 'Migration description, optionally grouped, e.g. add_status or reports/add_status.')
			->addOption('create', null, InputOption::VALUE_REQUIRED, 'Unprefixed table name created and dropped by this migration, e.g. your_plugin_reports.')
			->addOption('table', null, InputOption::VALUE_REQUIRED, 'Unprefixed table name altered by this migration, e.g. your_plugin_reports.');
	}

	/**
	 * Generate a migration in the configured discovery directory.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$migration = $this->migration($input);
			$this->fileWriter->write($migration);
		} catch (RuntimeException|InvalidArgumentException $exception) {
			$output->writeln('<error>' . $exception->getMessage() . '</error>');

			return Command::FAILURE;
		}

		$output->writeln(sprintf('<info>Created:</info> %s', $migration->relativePath));
		$output->writeln('Complete up() and down(), then preview with wp <prefix> migrate --run --dry-run.');
		$warning = $this->runtimeDependencyWarning();

		if ($warning !== null) {
			$output->writeln('<error>Runtime dependency missing:</error> ' . $warning);
		}

		return Command::SUCCESS;
	}

	/**
	 * Build the migration artifact selected by the generic, create, or alter mode.
	 *
	 * @throws RuntimeException         When options or project metadata are invalid.
	 * @throws InvalidArgumentException When the migration directory or identity is invalid.
	 */
	private function migration(InputInterface $input): GeneratedFile {
		$create = $this->tableOption($input, 'create');
		$table  = $this->tableOption($input, 'table');

		if ($create !== null && $table !== null) {
			throw new RuntimeException('The --create and --table options cannot be used together.');
		}

		$name = (string) $input->getArgument('name');

		if ($create !== null) {
			return $this->migrationFactory->createTable($name, $create);
		}

		if ($table !== null) {
			return $this->migrationFactory->alterTable($name, $table);
		}

		return $this->migrationFactory->generic($name);
	}

	/**
	 * Return a normalized table-name option while rejecting an explicit blank value.
	 *
	 * @throws RuntimeException When the option was supplied without a table name.
	 */
	private function tableOption(InputInterface $input, string $option): ?string {
		$value = $input->getOption($option);

		if ($value === null) {
			return null;
		}

		if (! is_string($value) || trim($value) === '') {
			throw new RuntimeException(sprintf('The --%s option cannot be blank.', $option));
		}

		return trim($value);
	}

	/**
	 * Explain when generated runtime code lacks a production Foundation dependency.
	 */
	private function runtimeDependencyWarning(): ?string {
		$composerPath = $this->projectDirectory->absolutePath('composer.json');

		if (! is_readable($composerPath)) {
			return null;
		}

		$composer = json_decode((string) file_get_contents($composerPath), true);

		if (! is_array($composer)) {
			return null;
		}

		$require    = is_array($composer['require'] ?? null) ? $composer['require'] : [];
		$requireDev = is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [];

		if ($this->hasFoundationRuntimeDependency($require)) {
			return null;
		}

		if ($this->hasFoundationRuntimeDependency($requireDev)) {
			return 'this migration uses Foundation Migrations classes, but the Foundation runtime package is only in require-dev. Move stellarwp/foundation-migrations or stellarwp/foundation to require before shipping this migration.';
		}

		return 'this migration uses Foundation Migrations classes. Run composer require stellarwp/foundation-migrations, or require stellarwp/foundation, before shipping this migration.';
	}

	/**
	 * Determine whether production dependencies include the Foundation migrations runtime.
	 *
	 * @param array<string,mixed> $dependencies
	 */
	private function hasFoundationRuntimeDependency(array $dependencies): bool {
		return array_key_exists('stellarwp/foundation-migrations', $dependencies)
			|| array_key_exists('stellarwp/foundation', $dependencies);
	}
}
