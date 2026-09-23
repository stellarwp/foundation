<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Make\Database;

use InvalidArgumentException;
use RuntimeException;
use StellarWP\Foundation\Cli\Commands\Make\Database\Factories\MigrationFileFactory;
use StellarWP\Foundation\Cli\Commands\Make\Database\ValueObjects\ProviderRegistrationResult;
use StellarWP\Foundation\Cli\Composer\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
use StellarWP\Foundation\Cli\Generation\GeneratorLocationResolver;
use StellarWP\Foundation\Cli\Generation\StubRenderer;
use StellarWP\Foundation\Cli\Generation\StubResolver;
use StellarWP\Foundation\Cli\Generation\ValueObjects\GeneratedFile;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use StellarWP\Foundation\Cli\Generation\WordPressClassNameResolver;
use StellarWP\Foundation\Database\DatabaseStubPath;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate a WordPress-style application table class for Foundation Database.
 *
 * Use this from a consuming WordPress project when a feature needs a stable
 * table identity and table-scoped database operations.
 */
final class TableCommand extends Command
{
	public const string CONFIG_KEY        = 'database-table';
	public const string NAME              = 'make:database:table';
	public const string DEFAULT_NAMESPACE = 'Database\\Tables';

	/**
	 * Create the table generator for a consuming project root.
	 */
	public function __construct(
		private readonly ProjectDirectory $projectDirectory,
		private readonly ComposerAutoloadResolver $autoloadResolver,
		private readonly GeneratorLocationResolver $locations,
		private readonly ProviderFileResolver $providerFiles,
		private readonly WordPressClassNameResolver $classNameResolver,
		private readonly StubResolver $stubResolver,
		private readonly StubRenderer $stubRenderer,
		private readonly GeneratedFileWriter $fileWriter,
		private readonly ProviderRegistrationEditor $providerUpdater,
		private readonly MigrationFileFactory $migrationFactory
	) {
		parent::__construct(self::NAME);
	}

	/**
	 * Define table generation options and optional initial-migration behavior.
	 */
	protected function configure(): void {
		$this->setDescription('Generate a Foundation database table class.')
			->addArgument('name', InputArgument::REQUIRED, 'Table class name, e.g. Reports_Table, Reports, or reports.')
			->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace for the generated table, e.g. Plugin\Database\Tables.')
			->addOption('path', null, InputOption::VALUE_REQUIRED, 'Output directory for the generated table, e.g. src/Database/Tables.')
			->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Database provider file to update, e.g. src/Database/Provider.php.')
			->addOption('table-name', null, InputOption::VALUE_REQUIRED, 'Unprefixed WordPress table name using letters, numbers, and underscores, e.g. report_entries.')
			->addOption('migration', 'm', InputOption::VALUE_NONE, 'Also create the table\'s initial migration. Rolling it back drops the complete table and its data.');
	}

	/**
	 * Generate the table, its optional initial migration, and provider registrations.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$writtenFiles = [];

		try {
			$table     = $this->generatedFile($input);
			$migration = $input->getOption('migration') === true
				? $this->initialMigration($input)
				: null;

			$files = $migration === null ? [$table] : [$table, $migration];

			$this->validateExplicitProviderUpdate($input);
			$this->fileWriter->writeAll(...$files);
			$writtenFiles = $files;

			$providerPath = $this->updateProvider($input, $output);
		} catch (RuntimeException|InvalidArgumentException $exception) {
			$output->writeln('<error>' . $this->failureMessage($exception, $writtenFiles) . '</error>');

			return Command::FAILURE;
		}

		$output->writeln(sprintf('<info>Created:</info> %s', $table->relativePath));

		if ($migration !== null) {
			$output->writeln(sprintf('<info>Created:</info> %s', $migration->relativePath));
		}

		$output->writeln('');

		if ($migration === null) {
			$output->writeln('<comment>Create a migration that defines this table with Blueprint::create().</comment>');
		} elseif ($providerPath === null && ! $this->providerExists($input)) {
			$output->writeln(sprintf(
				'<comment>Register %s with your database provider when it needs shared or custom wiring.</comment>',
				$this->classNameResolver->tableClass((string) $input->getArgument('name')),
			));
		}

		if ($providerPath !== null) {
			$output->writeln(sprintf('<info>Updated:</info> %s', $this->projectDirectory->relativePath($providerPath)));
		}

		$runtimeDependencyWarning = $this->runtimeDependencyWarning();

		if ($runtimeDependencyWarning !== null) {
			$output->writeln('');
			$output->writeln('<error>Runtime dependency missing:</error> ' . $runtimeDependencyWarning);
		}

		return Command::SUCCESS;
	}

	/**
	 * Build the create-table migration paired with a generated table.
	 *
	 * @throws RuntimeException When migration input or project metadata is invalid.
	 */
	private function initialMigration(InputInterface $input): GeneratedFile {
		$tableClass = $this->classNameResolver->tableClass((string) $input->getArgument('name'));

		return $this->migrationFactory->createTable(
			name: 'create_' . $tableClass,
			table: $this->tableName($input, $tableClass),
		);
	}

	/**
	 * Render the table artifact from normalized project and command input.
	 *
	 * @throws RuntimeException When project metadata or generator input is invalid.
	 */
	private function generatedFile(InputInterface $input): GeneratedFile {
		$className = $this->classNameResolver->tableClass((string) $input->getArgument('name'));
		$project   = $this->autoloadResolver->project();
		$namespace = $this->locations->namespaceFor(self::CONFIG_KEY, self::DEFAULT_NAMESPACE, $project, (string) $input->getOption('namespace'));
		$path      = $this->locations->directoryFor($namespace, $project, (string) $input->getOption('path'));
		$stub      = $this->stubResolver->resolve('database', 'table', DatabaseStubPath::table());
		$relative  = $this->projectDirectory->relativePath($path . '/' . $className . '.php');
		$table     = $this->tableName($input, $className);

		return new GeneratedFile(
			path: $path . '/' . $className . '.php',
			relativePath: $relative,
			contents: $this->stubRenderer->render($stub, [
				'namespace'                 => $namespace,
				'class'                     => $className,
				'table_php'                 => $this->stubRenderer->phpStringLiteral($table),
				'foundation_database_table' => $project->foundationClass('StellarWP\\Foundation\\Database\\Table\\Table'),
			])
		);
	}

	/**
	 * Fail before writing files when an explicitly selected provider cannot be updated.
	 *
	 * @throws RuntimeException When the provider cannot accept the generated registrations.
	 */
	private function validateExplicitProviderUpdate(InputInterface $input): void {
		$project      = $this->autoloadResolver->project();
		$className    = $this->classNameResolver->tableClass((string) $input->getArgument('name'));
		$namespace    = $this->locations->namespaceFor(self::CONFIG_KEY, self::DEFAULT_NAMESPACE, $project, (string) $input->getOption('namespace'));
		$providerPath = $this->providerFiles->resolve($project, $this->nullableOption($input, 'provider'));

		if (! $this->hasExplicitProvider($input)) {
			return;
		}

		if (! is_file($providerPath)) {
			throw new RuntimeException(sprintf('Could not update database provider "%s": file does not exist.', $this->projectDirectory->relativePath($providerPath)));
		}

		$result = $this->providerUpdater->checkTable($providerPath, $className, $namespace);

		if (! $result->succeeded()) {
			throw new RuntimeException(sprintf(
				'Could not update database provider "%s": %s.',
				$this->projectDirectory->relativePath($providerPath),
				$result->failureReason() ?? 'provider could not be updated'
			));
		}
	}

	/**
	 * Update the selected or conventional provider and report non-fatal automatic failures.
	 *
	 * @throws RuntimeException When an explicitly selected provider cannot be updated.
	 */
	private function updateProvider(InputInterface $input, OutputInterface $output): ?string {
		$project      = $this->autoloadResolver->project();
		$className    = $this->classNameResolver->tableClass((string) $input->getArgument('name'));
		$namespace    = $this->locations->namespaceFor(self::CONFIG_KEY, self::DEFAULT_NAMESPACE, $project, (string) $input->getOption('namespace'));
		$providerPath = $this->providerFiles->resolve($project, $this->nullableOption($input, 'provider'));
		$explicit     = $this->hasExplicitProvider($input);

		if (! is_file($providerPath)) {
			if ($explicit) {
				throw new RuntimeException(sprintf('Could not update database provider "%s": file does not exist.', $this->projectDirectory->relativePath($providerPath)));
			}

			return null;
		}

		$result = $this->providerUpdater->addTable($providerPath, $className, $namespace);

		if (! $result->succeeded() && $explicit) {
			throw new RuntimeException(sprintf(
				'Could not update database provider "%s": %s.',
				$this->projectDirectory->relativePath($providerPath),
				$result->failureReason() ?? 'provider could not be updated'
			));
		}

		if (! $result->succeeded()) {
			$this->writeProviderWarning($output, $providerPath, $result, $className);
		}

		return $result->wasUpdated() ? $providerPath : null;
	}

	/**
	 * Remove files written by a failed command and combine any cleanup failure message.
	 *
	 * @param list<GeneratedFile> $writtenFiles
	 */
	private function failureMessage(RuntimeException|InvalidArgumentException $exception, array $writtenFiles): string {
		try {
			$this->fileWriter->remove(...$writtenFiles);
		} catch (RuntimeException $cleanupException) {
			return $exception->getMessage() . ' ' . $cleanupException->getMessage();
		}

		return $exception->getMessage();
	}

	/**
	 * Report a non-fatal conventional-provider update failure.
	 */
	private function writeProviderWarning(OutputInterface $output, string $providerPath, ProviderRegistrationResult $result, string $className): void {
		$output->writeln(sprintf(
			'<comment>Provider not updated:</comment> %s (%s). Register %s manually.',
			$this->projectDirectory->relativePath($providerPath),
			$result->failureReason() ?? 'provider could not be updated',
			$className
		));
	}

	/**
	 * Resolve the stable unprefixed table name stored in generated source.
	 *
	 * @throws RuntimeException When --table-name cannot be used as an unprefixed WordPress table name.
	 */
	private function tableName(InputInterface $input, string $className): string {
		$value = $input->getOption('table-name');

		if ($value === null) {
			return $this->classNameResolver->tableName($className);
		}

		if (! is_string($value) || $value === '' || trim($value) !== $value) {
			throw new RuntimeException('The --table-name option cannot be blank or contain surrounding whitespace.');
		}

		if (preg_match('/\A[A-Za-z0-9_]+\z/', $value) !== 1) {
			throw new RuntimeException('The --table-name option may contain only ASCII letters, numbers, and underscores.');
		}

		return $value;
	}

	/**
	 * Return a string option or null when the option was omitted.
	 */
	private function nullableOption(InputInterface $input, string $option): ?string {
		$value = $input->getOption($option);

		return is_string($value) ? $value : null;
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
		return is_file($this->providerFiles->resolve($this->autoloadResolver->project(), $this->nullableOption($input, 'provider')));
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
			return 'this table uses Foundation Database classes, but the Foundation runtime package is only in require-dev. Move stellarwp/foundation-database or stellarwp/foundation to require before shipping this table.';
		}

		return 'this table uses Foundation Database classes. Run composer require stellarwp/foundation-database, or require stellarwp/foundation, before shipping this table.';
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
