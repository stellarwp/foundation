<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Make\Database;

use RuntimeException;
use StellarWP\Foundation\Cli\Commands\Make\Database\Factories\MigrationFileFactory;
use StellarWP\Foundation\Cli\Commands\Make\Database\ValueObjects\GeneratedMigration;
use StellarWP\Foundation\Cli\Generation\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ComposerProject;
use StellarWP\Foundation\Cli\Generation\ValueObjects\GeneratedFile;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates a WordPress-style migration class for Foundation Database.
 *
 * Use this from a consuming WordPress project when a feature needs a versioned
 * database change that can be registered with `DatabaseProvider`.
 */
final class MigrationCommand extends Command
{
	private const string NAME = 'make:database-migration';

	/**
	 * Create the migration generator for a consuming project root.
	 */
	public function __construct(
		private readonly ProjectDirectory $projectDirectory,
		private readonly ComposerAutoloadResolver $autoloadResolver,
		private readonly MigrationFileFactory $migrationFactory,
		private readonly GeneratedFileWriter $fileWriter,
		private readonly ProviderRegistrationEditor $providerUpdater
	) {
		parent::__construct(self::NAME);
	}

	/**
	 * Define the migration generation modes and project customization options.
	 */
	protected function configure(): void {
		$this->setDescription('Create a new Foundation database migration.')
			->setHelp('Use --create for a table owned by this migration, --table to reconcile an existing table, or neither for a generic migration. The table options are mutually exclusive and accept short or fully qualified class names.')
			->addArgument('name', InputArgument::REQUIRED, 'Migration class name, e.g. Create_Reports_Table, Bump_Version, or create-reports-table.')
			->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace for the generated migration, e.g. Plugin\Database\Migrations.')
			->addOption('path', null, InputOption::VALUE_REQUIRED, 'Output directory for the generated migration, e.g. src/Database/Migrations.')
			->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Database provider file to update, e.g. src/Database/Provider.php.')
			->addOption('id', null, InputOption::VALUE_REQUIRED, 'Stable identifier that determines execution order, e.g. 2026_09_04_143200_create_reports_table.')
			->addOption('create', null, InputOption::VALUE_REQUIRED, 'Table class created and dropped by this migration, e.g. Reports_Table or Plugin\Database\Tables\Reports_Table.')
			->addOption('table', null, InputOption::VALUE_REQUIRED, 'Existing table class reconciled by this migration, e.g. Reports_Table or Plugin\Database\Tables\Reports_Table.');
	}

	/**
	 * Generate the selected migration and update its database provider when available.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$writtenFiles = [];

		try {
			$migration = $this->migration($input);
			$this->validateExplicitProviderUpdate($input, $migration);

			if (file_exists($migration->file->path)) {
				throw new RuntimeException(sprintf(
					'Migration already exists: %s. Edit it directly or create a new migration.',
					$migration->file->relativePath
				));
			}

			$this->fileWriter->write($migration->file);
			$writtenFiles[] = $migration->file;
			$providerPath   = $this->updateProvider($input, $output, $migration);
		} catch (RuntimeException $exception) {
			$output->writeln('<error>' . $this->failureMessage($exception, $writtenFiles) . '</error>');

			return Command::FAILURE;
		}

		$output->writeln(sprintf('<info>Created:</info> %s', $migration->file->relativePath));

		if ($providerPath !== null) {
			$output->writeln(sprintf('<info>Updated:</info> %s', $this->projectDirectory->relativePath($providerPath)));
		} elseif (! $this->providerExists($input)) {
			$output->writeln('');
			$output->writeln('<comment>Register this migration with DatabaseProvider::MIGRATIONS using mergeArrayVar().</comment>');
		}

		$runtimeDependencyWarning = $this->runtimeDependencyWarning();

		if ($runtimeDependencyWarning !== null) {
			$output->writeln('');
			$output->writeln('<error>Runtime dependency missing:</error> ' . $runtimeDependencyWarning);
		}

		return Command::SUCCESS;
	}

	/**
	 * Build the migration artifact selected by the generic, create, or reconcile mode.
	 *
	 * @throws RuntimeException When options or project metadata are invalid.
	 */
	private function migration(InputInterface $input): GeneratedMigration {
		$create = $this->tableOption($input, 'create');
		$table  = $this->tableOption($input, 'table');

		if ($create !== null && $table !== null) {
			throw new RuntimeException('The --create and --table options cannot be used together.');
		}

		$name      = (string) $input->getArgument('name');
		$namespace = $this->nullableOption($input, 'namespace');
		$path      = $this->nullableOption($input, 'path');
		$id        = $this->nullableOption($input, 'id');

		if ($create !== null) {
			return $this->migrationFactory->createTable($name, $create, $namespace, $path, $id);
		}

		if ($table !== null) {
			return $this->migrationFactory->reconcileTable($name, $table, $namespace, $path, $id);
		}

		return $this->migrationFactory->generic($name, $namespace, $path, $id);
	}

	/**
	 * Fail before writing the migration when an explicitly selected provider cannot be updated.
	 *
	 * @throws RuntimeException When the provider cannot accept the migration registration.
	 */
	private function validateExplicitProviderUpdate(InputInterface $input, GeneratedMigration $migration): void {
		if (! $this->hasExplicitProvider($input)) {
			return;
		}

		$project      = $this->autoloadResolver->project();
		$providerPath = $this->providerPath($input, $project);

		if (! is_file($providerPath)) {
			throw new RuntimeException(sprintf('Could not update database provider "%s": file does not exist.', $this->projectDirectory->relativePath($providerPath)));
		}

		$result = $this->providerUpdater->checkMigration($providerPath, $migration->class, $migration->namespace);

		if ($result->succeeded()) {
			return;
		}

		throw new RuntimeException(sprintf(
			'Could not update database provider "%s": %s.',
			$this->projectDirectory->relativePath($providerPath),
			$result->failureReason() ?? 'provider could not be updated'
		));
	}

	/**
	 * Update the selected or conventional provider and report non-fatal automatic failures.
	 *
	 * @throws RuntimeException When an explicitly selected provider cannot be updated.
	 */
	private function updateProvider(InputInterface $input, OutputInterface $output, GeneratedMigration $migration): ?string {
		$project      = $this->autoloadResolver->project();
		$providerPath = $this->providerPath($input, $project);
		$explicit     = $this->hasExplicitProvider($input);

		if (! is_file($providerPath)) {
			if ($explicit) {
				throw new RuntimeException(sprintf('Could not update database provider "%s": file does not exist.', $this->projectDirectory->relativePath($providerPath)));
			}

			return null;
		}

		$result = $this->providerUpdater->addMigration($providerPath, $migration->class, $migration->namespace);

		if ($result->wasUpdated()) {
			return $providerPath;
		}

		if ($result->succeeded()) {
			return null;
		}

		if ($explicit) {
			throw new RuntimeException(sprintf(
				'Could not update database provider "%s": %s.',
				$this->projectDirectory->relativePath($providerPath),
				$result->failureReason() ?? 'provider could not be updated'
			));
		}

		$output->writeln(sprintf(
			'<comment>Provider not updated:</comment> %s (%s). Register %s manually.',
			$this->projectDirectory->relativePath($providerPath),
			$result->failureReason() ?? 'provider could not be updated',
			$migration->class
		));

		return null;
	}

	/**
	 * Remove files written by a failed command and combine any cleanup failure message.
	 *
	 * @param list<GeneratedFile> $writtenFiles
	 */
	private function failureMessage(RuntimeException $exception, array $writtenFiles): string {
		try {
			$this->fileWriter->remove(...$writtenFiles);
		} catch (RuntimeException $cleanupException) {
			return $exception->getMessage() . ' ' . $cleanupException->getMessage();
		}

		return $exception->getMessage();
	}

	/**
	 * Return a normalized table-class option while rejecting an explicit blank value.
	 *
	 * @throws RuntimeException When the option was supplied without a class name.
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
	 * Return a string option or null when the option was omitted.
	 */
	private function nullableOption(InputInterface $input, string $option): ?string {
		$value = $input->getOption($option);

		return is_string($value) ? $value : null;
	}

	/**
	 * Resolve the explicit provider path or the project's conventional database provider.
	 */
	private function providerPath(InputInterface $input, ComposerProject $project): string {
		$provider = $input->getOption('provider');

		if (is_string($provider) && trim($provider) !== '') {
			return $this->projectDirectory->absolutePath($provider);
		}

		$namespace = trim($project->defaultPsr4Namespace()->namespace, '\\') . '\\Database';
		$autoload  = $project->psr4NamespaceFor($namespace);

		if ($autoload === null) {
			return $this->projectDirectory->absolutePath('src/Database/Provider.php');
		}

		return $this->projectDirectory->absolutePath($autoload->pathFor($namespace) . '/Provider.php');
	}

	/**
	 * Determine whether the developer explicitly selected a provider file.
	 */
	private function hasExplicitProvider(InputInterface $input): bool {
		$provider = $input->getOption('provider');

		return is_string($provider) && trim($provider) !== '';
	}

	/**
	 * Determine whether the selected or conventional provider file exists.
	 */
	private function providerExists(InputInterface $input): bool {
		return is_file($this->providerPath($input, $this->autoloadResolver->project()));
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
			return 'this migration uses Foundation Database classes, but the Foundation runtime package is only in require-dev. Move stellarwp/foundation-database or stellarwp/foundation to require before shipping this migration.';
		}

		return 'this migration uses Foundation Database classes. Run composer require stellarwp/foundation-database, or require stellarwp/foundation, before shipping this migration.';
	}

	/**
	 * Determine whether production dependencies include the Foundation database runtime.
	 *
	 * @param array<string,mixed> $dependencies
	 */
	private function hasFoundationRuntimeDependency(array $dependencies): bool {
		return array_key_exists('stellarwp/foundation-database', $dependencies)
			|| array_key_exists('stellarwp/foundation', $dependencies);
	}
}
