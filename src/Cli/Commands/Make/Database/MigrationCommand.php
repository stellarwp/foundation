<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Make\Database;

use RuntimeException;
use StellarWP\Foundation\Cli\Commands\Make\Database\Factories\MigrationFileFactory;
use StellarWP\Foundation\Cli\Commands\Make\Database\ValueObjects\GeneratedMigration;
use StellarWP\Foundation\Cli\Generation\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ComposerProject;
use StellarWP\Foundation\Cli\Generation\ValueObjects\GeneratedFile;
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
		private readonly string $rootPath,
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
			->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace for the generated migration class.')
			->addOption('path', null, InputOption::VALUE_REQUIRED, 'Directory where the migration class should be written.')
			->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Database provider file to update when it exists.')
			->addOption('id', null, InputOption::VALUE_REQUIRED, 'Stable migration identifier: nonblank, unpadded, non-integer-like, and at most 191 bytes.')
			->addOption('create', null, InputOption::VALUE_REQUIRED, 'Short or fully qualified table class created and dropped by this migration.')
			->addOption('table', null, InputOption::VALUE_REQUIRED, 'Short or fully qualified existing table class reconciled by this migration.');
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
			$output->writeln(sprintf('<info>Updated:</info> %s', $this->relativePath($providerPath)));
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
			throw new RuntimeException(sprintf('Could not update database provider "%s": file does not exist.', $this->relativePath($providerPath)));
		}

		$status = $this->providerUpdater->checkMigration($providerPath, $migration->class, $migration->namespace);

		if ($status === ProviderRegistrationEditor::UPDATED || $status === ProviderRegistrationEditor::ALREADY_REGISTERED) {
			return;
		}

		throw new RuntimeException(sprintf(
			'Could not update database provider "%s": %s.',
			$this->relativePath($providerPath),
			$this->providerUpdateFailure($status)
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
				throw new RuntimeException(sprintf('Could not update database provider "%s": file does not exist.', $this->relativePath($providerPath)));
			}

			return null;
		}

		$status = $this->providerUpdater->addMigration($providerPath, $migration->class, $migration->namespace);

		if ($status === ProviderRegistrationEditor::UPDATED) {
			return $providerPath;
		}

		if ($status === ProviderRegistrationEditor::ALREADY_REGISTERED) {
			return null;
		}

		if ($explicit) {
			throw new RuntimeException(sprintf(
				'Could not update database provider "%s": %s.',
				$this->relativePath($providerPath),
				$this->providerUpdateFailure($status)
			));
		}

		$output->writeln(sprintf(
			'<comment>Provider not updated:</comment> %s (%s). Register %s manually.',
			$this->relativePath($providerPath),
			$this->providerUpdateFailure($status),
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
			return $this->absolutePath($provider);
		}

		$namespace = trim($project->defaultPsr4Namespace()->namespace, '\\') . '\\Database';
		$autoload  = $project->psr4NamespaceFor($namespace);

		if ($autoload === null) {
			return $this->rootPath . '/src/Database/Provider.php';
		}

		return $this->rootPath . '/' . $autoload->pathFor($namespace) . '/Provider.php';
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
	 * Translate an editor status into an actionable console message.
	 */
	private function providerUpdateFailure(string $status): string {
		return match ($status) {
			ProviderRegistrationEditor::NOT_FOUND        => 'file does not exist or is not readable',
			ProviderRegistrationEditor::READ_FAILED      => 'file could not be read',
			ProviderRegistrationEditor::NOT_WRITABLE     => 'file is not writable',
			ProviderRegistrationEditor::MISSING_ANCHOR   => 'file does not contain a generated database provider registration point',
			ProviderRegistrationEditor::MISSING_MARKER   => 'file does not contain the generated database provider markers',
			ProviderRegistrationEditor::IMPORT_COLLISION => 'another class declaration or import uses the same short class name',
			ProviderRegistrationEditor::PARSE_FAILED     => 'file could not be parsed as PHP',
			ProviderRegistrationEditor::WRITE_FAILED     => 'file could not be written',
			default                                      => 'provider could not be updated',
		};
	}

	/**
	 * Resolve a project-relative path without changing an absolute path.
	 */
	private function absolutePath(string $path): string {
		$path = trim($path);

		if (str_starts_with($path, '/')) {
			return rtrim($path, '/');
		}

		return $this->rootPath . '/' . trim($path, '/');
	}

	/**
	 * Return a project-relative path for console output when possible.
	 */
	private function relativePath(string $path): string {
		$root = rtrim($this->rootPath, '/') . '/';

		if (str_starts_with($path, $root)) {
			return substr($path, strlen($root));
		}

		return $path;
	}

	/**
	 * Explain when generated runtime code lacks a production Foundation dependency.
	 */
	private function runtimeDependencyWarning(): ?string {
		$composerPath = $this->rootPath . '/composer.json';

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
