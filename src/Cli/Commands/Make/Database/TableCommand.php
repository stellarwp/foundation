<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Make\Database;

use RuntimeException;
use StellarWP\Foundation\Cli\Commands\Make\Database\Factories\MigrationFileFactory;
use StellarWP\Foundation\Cli\Commands\Make\Database\ValueObjects\GeneratedMigration;
use StellarWP\Foundation\Cli\Generation\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
use StellarWP\Foundation\Cli\Generation\StubRenderer;
use StellarWP\Foundation\Cli\Generation\StubResolver;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ComposerProject;
use StellarWP\Foundation\Cli\Generation\ValueObjects\GeneratedFile;
use StellarWP\Foundation\Cli\Generation\ValueObjects\Psr4Namespace;
use StellarWP\Foundation\Cli\Generation\WordPressClassNameResolver;
use StellarWP\Foundation\Database\DatabaseStubPath;
use StellarWP\Foundation\Database\Migration\ValueObjects\Id;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates a WordPress-style table class for Foundation Database migrations.
 *
 * Use this from a consuming WordPress project when a feature needs a table
 * definition that can be applied by a Foundation migration.
 */
final class TableCommand extends Command
{
	private const string NAME = 'make:database-table';

	public function __construct(
		private readonly string $rootPath,
		private readonly ComposerAutoloadResolver $autoloadResolver,
		private readonly WordPressClassNameResolver $classNameResolver,
		private readonly StubResolver $stubResolver,
		private readonly StubRenderer $stubRenderer,
		private readonly GeneratedFileWriter $fileWriter,
		private readonly ProviderRegistrationEditor $providerUpdater,
		private readonly MigrationFileFactory $migrationFactory
	) {
		parent::__construct(self::NAME);
	}

	protected function configure(): void {
		$this->setDescription('Generate a Foundation database table class.')
			->addArgument('name', InputArgument::REQUIRED, 'Table class name, e.g. Reports_Table, Reports, or reports.')
			->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace for the generated table class.')
			->addOption('path', null, InputOption::VALUE_REQUIRED, 'Directory where the table class should be written.')
			->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Database provider file to update when it exists.')
			->addOption('id', null, InputOption::VALUE_REQUIRED, 'Stable table identifier: nonblank, unpadded, non-integer-like, and at most 191 bytes.')
			->addOption('table-name', null, InputOption::VALUE_REQUIRED, 'Unprefixed WordPress table name.')
			->addOption('migration', 'm', InputOption::VALUE_NONE, 'Also create the table\'s initial migration.')
			->addOption('migration-id', null, InputOption::VALUE_REQUIRED, 'Stable identifier for the initial migration. Requires --migration.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$writtenFiles = [];

		try {
			$this->validateMigrationOptions($input);
			$table     = $this->generatedFile($input);
			$migration = $input->getOption('migration') === true
				? $this->initialMigration($input)
				: null;

			$this->validateGeneratedFiles($table, $migration);
			$this->validateExplicitProviderUpdate($input, $migration);

			if ($migration === null) {
				$this->fileWriter->write($table);
				$writtenFiles[] = $table;
			} else {
				$this->fileWriter->writeAll($table, $migration->file);
				$writtenFiles[] = $table;
				$writtenFiles[] = $migration->file;
			}

			$providerPath = $this->updateProvider($input, $output, $migration);
		} catch (RuntimeException $exception) {
			$output->writeln('<error>' . $this->failureMessage($exception, $writtenFiles) . '</error>');

			return Command::FAILURE;
		}

		$output->writeln(sprintf('<info>Created:</info> %s', $table->relativePath));

		if ($migration !== null) {
			$output->writeln(sprintf('<info>Created:</info> %s', $migration->file->relativePath));
		}

		$output->writeln('');

		if ($migration === null) {
			$output->writeln('<comment>Add this table to a migration with Schema::createOrUpdate() and Schema::drop().</comment>');
		} elseif ($providerPath === null && ! $this->providerExists($input)) {
			$output->writeln(sprintf(
				'<comment>Register %s and %s with your database provider.</comment>',
				$this->classNameResolver->tableClass((string) $input->getArgument('name')),
				$migration->class
			));
		}

		if ($providerPath !== null) {
			$output->writeln(sprintf('<info>Updated:</info> %s', $this->relativePath($providerPath)));
		}

		$runtimeDependencyWarning = $this->runtimeDependencyWarning();

		if ($runtimeDependencyWarning !== null) {
			$output->writeln('');
			$output->writeln('<error>Runtime dependency missing:</error> ' . $runtimeDependencyWarning);
		}

		return Command::SUCCESS;
	}

	private function initialMigration(InputInterface $input): GeneratedMigration {
		$project        = $this->autoloadResolver->project();
		$tableClass     = $this->classNameResolver->tableClass((string) $input->getArgument('name'));
		$tableNamespace = $this->namespace($input, $project->defaultPsr4Namespace());

		return $this->migrationFactory->createTable(
			name: 'Create_' . $tableClass,
			tableClass: $tableNamespace . '\\' . $tableClass,
			id: $this->nullableOption($input, 'migration-id')
		);
	}

	private function validateMigrationOptions(InputInterface $input): void {
		if ($input->getOption('migration') === true || $input->getOption('migration-id') === null) {
			return;
		}

		throw new RuntimeException('The --migration-id option requires --migration.');
	}

	private function validateGeneratedFiles(GeneratedFile $table, ?GeneratedMigration $migration): void {
		$this->fileWriter->validate($table);

		if (file_exists($table->path)) {
			throw new RuntimeException(sprintf('File already exists: %s.', $table->relativePath));
		}

		if ($migration === null) {
			return;
		}

		$this->fileWriter->validate($migration->file);

		if (file_exists($migration->file->path)) {
			throw new RuntimeException(sprintf(
				'Migration already exists: %s. Edit it directly or create a new migration.',
				$migration->file->relativePath
			));
		}
	}

	private function generatedFile(InputInterface $input): GeneratedFile {
		$className = $this->classNameResolver->tableClass((string) $input->getArgument('name'));
		$project   = $this->autoloadResolver->project();
		$namespace = $this->namespace($input, $project->defaultPsr4Namespace());
		$path      = $this->path($input, $namespace, $project);
		$stub      = $this->stubResolver->resolve('database', 'table', DatabaseStubPath::table());
		$relative  = $this->relativePath($path . '/' . $className . '.php');
		$table     = $this->tableName($input, $className);
		$idOption  = $input->getOption('id');
		$id        = (new Id(is_string($idOption) ? $idOption : $table . '_table'))->value;

		return new GeneratedFile(
			path: $path . '/' . $className . '.php',
			relativePath: $relative,
			contents: $this->stubRenderer->render($stub, [
				'namespace'                            => $namespace,
				'class'                                => $className,
				'id_php'                               => $this->phpString($id),
				'table_php'                            => $this->phpString($table),
				'foundation_database_contract'         => $project->foundationClass('StellarWP\\Foundation\\Database\\Contracts\\Database'),
				'foundation_database_table'            => $project->foundationClass('StellarWP\\Foundation\\Database\\Table\\Table'),
				'foundation_database_table_definition' => $project->foundationClass('StellarWP\\Foundation\\Database\\Table\\TableDefinition'),
			])
		);
	}

	private function validateExplicitProviderUpdate(InputInterface $input, ?GeneratedMigration $migration): void {
		if (! $this->hasExplicitProvider($input)) {
			return;
		}

		$project      = $this->autoloadResolver->project();
		$className    = $this->classNameResolver->tableClass((string) $input->getArgument('name'));
		$namespace    = $this->namespace($input, $project->defaultPsr4Namespace());
		$providerPath = $this->providerPath($input, $project);

		if (! is_file($providerPath)) {
			throw new RuntimeException(sprintf('Could not update database provider "%s": file does not exist.', $this->relativePath($providerPath)));
		}

		$status = $migration === null
			? $this->providerUpdater->checkTable($providerPath, $className, $namespace)
			: $this->providerUpdater->checkTableAndMigration(
				$providerPath,
				$className,
				$namespace,
				$migration->class,
				$migration->namespace
			);

		if ($status !== ProviderRegistrationEditor::UPDATED && $status !== ProviderRegistrationEditor::ALREADY_REGISTERED) {
			throw new RuntimeException(sprintf(
				'Could not update database provider "%s": %s.',
				$this->relativePath($providerPath),
				$this->providerUpdateFailure($status)
			));
		}
	}

	/**
	 * @throws RuntimeException When an explicitly selected provider cannot be updated.
	 */
	private function updateProvider(InputInterface $input, OutputInterface $output, ?GeneratedMigration $migration): ?string {
		$project      = $this->autoloadResolver->project();
		$className    = $this->classNameResolver->tableClass((string) $input->getArgument('name'));
		$namespace    = $this->namespace($input, $project->defaultPsr4Namespace());
		$providerPath = $this->providerPath($input, $project);
		$explicit     = $this->hasExplicitProvider($input);

		if (! is_file($providerPath)) {
			if ($explicit) {
				throw new RuntimeException(sprintf('Could not update database provider "%s": file does not exist.', $this->relativePath($providerPath)));
			}

			return null;
		}

		$status = $migration === null
			? $this->providerUpdater->addTable($providerPath, $className, $namespace)
			: $this->providerUpdater->addTableAndMigration(
				$providerPath,
				$className,
				$namespace,
				$migration->class,
				$migration->namespace
			);

		if ($status !== ProviderRegistrationEditor::UPDATED && $status !== ProviderRegistrationEditor::ALREADY_REGISTERED && $explicit) {
			throw new RuntimeException(sprintf(
				'Could not update database provider "%s": %s.',
				$this->relativePath($providerPath),
				$this->providerUpdateFailure($status)
			));
		}

		if ($status !== ProviderRegistrationEditor::UPDATED && $status !== ProviderRegistrationEditor::ALREADY_REGISTERED) {
			$classes = $migration === null
				? $className
				: $className . ' and ' . $migration->class;

			$this->writeProviderWarning($output, $providerPath, $status, $classes);
		}

		return $status === ProviderRegistrationEditor::UPDATED ? $providerPath : null;
	}

	/**
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

	private function writeProviderWarning(OutputInterface $output, string $providerPath, string $status, string $className): void {
		$output->writeln(sprintf(
			'<comment>Provider not updated:</comment> %s (%s). Register %s manually.',
			$this->relativePath($providerPath),
			$this->providerUpdateFailure($status),
			$className
		));
	}

	private function tableName(InputInterface $input, string $className): string {
		$value = $input->getOption('table-name');

		if ($value === null) {
			return $this->classNameResolver->tableName($className);
		}

		if (! is_string($value) || trim($value) === '') {
			throw new RuntimeException('The --table-name option cannot be blank.');
		}

		return trim($value);
	}

	private function nullableOption(InputInterface $input, string $option): ?string {
		$value = $input->getOption($option);

		return is_string($value) ? $value : null;
	}

	private function phpString(string $value): string {
		return var_export($value, true);
	}

	private function namespace(InputInterface $input, Psr4Namespace $autoload): string {
		$namespace = $input->getOption('namespace');

		if (is_string($namespace) && trim($namespace) !== '') {
			return $this->validNamespace(trim($namespace, '\\'));
		}

		return trim($autoload->namespace, '\\') . '\\Database\\Tables';
	}

	private function path(InputInterface $input, string $namespace, ComposerProject $project): string {
		$path = $input->getOption('path');

		if (is_string($path) && trim($path) !== '') {
			return $this->absolutePath($path);
		}

		$autoload = $project->psr4NamespaceFor($namespace);

		if ($autoload === null) {
			throw new RuntimeException(sprintf(
				'Namespace "%s" is outside the Composer PSR-4 namespaces in composer.json. Pass --path to choose an output directory.',
				$namespace
			));
		}

		return $this->rootPath . '/' . $autoload->pathFor($namespace);
	}

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

	private function hasExplicitProvider(InputInterface $input): bool {
		$provider = $input->getOption('provider');

		return is_string($provider) && trim($provider) !== '';
	}

	private function providerExists(InputInterface $input): bool {
		return is_file($this->providerPath($input, $this->autoloadResolver->project()));
	}

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

	private function absolutePath(string $path): string {
		$path = trim($path);

		if (str_starts_with($path, '/')) {
			return rtrim($path, '/');
		}

		return $this->rootPath . '/' . trim($path, '/');
	}

	private function relativePath(string $path): string {
		$root = rtrim($this->rootPath, '/') . '/';

		if (str_starts_with($path, $root)) {
			return substr($path, strlen($root));
		}

		return $path;
	}

	private function validNamespace(string $namespace): string {
		if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $namespace)) {
			throw new RuntimeException(sprintf('Namespace "%s" is not a valid PHP namespace.', $namespace));
		}

		return $namespace;
	}

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
			return 'this table uses Foundation Database classes, but the Foundation runtime package is only in require-dev. Move stellarwp/foundation-database or stellarwp/foundation to require before shipping this table.';
		}

		return 'this table uses Foundation Database classes. Run composer require stellarwp/foundation-database, or require stellarwp/foundation, before shipping this table.';
	}

	/**
	 * @param array<string,mixed> $dependencies
	 */
	private function hasFoundationRuntimeDependency(array $dependencies): bool {
		return array_key_exists('stellarwp/foundation-database', $dependencies)
			|| array_key_exists('stellarwp/foundation', $dependencies);
	}
}
