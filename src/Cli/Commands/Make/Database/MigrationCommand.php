<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Make\Database;

use RuntimeException;
use StellarWP\Foundation\Cli\Generation\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
use StellarWP\Foundation\Cli\Generation\StubRenderer;
use StellarWP\Foundation\Cli\Generation\StubResolver;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ComposerProject;
use StellarWP\Foundation\Cli\Generation\ValueObjects\GeneratedFile;
use StellarWP\Foundation\Cli\Generation\ValueObjects\Psr4Namespace;
use StellarWP\Foundation\Cli\Generation\WordPressClassNameResolver;
use StellarWP\Foundation\Database\DatabaseStubPath;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates a WordPress-style migration class for Foundation Database.
 *
 * Use this from a consuming WordPress project when a feature needs a versioned,
 * reversible schema change that can be registered with `DatabaseProvider`.
 */
final class MigrationCommand extends Command
{
	private const string NAME = 'make:database-migration';

	public function __construct(
		private readonly string $rootPath,
		private readonly ComposerAutoloadResolver $autoloadResolver,
		private readonly WordPressClassNameResolver $classNameResolver,
		private readonly StubResolver $stubResolver,
		private readonly StubRenderer $stubRenderer,
		private readonly GeneratedFileWriter $fileWriter,
		private readonly ProviderRegistrationEditor $providerUpdater
	) {
		parent::__construct(self::NAME);
	}

	protected function configure(): void {
		$this->setDescription('Generate a Foundation database migration class.')
			->addArgument('name', InputArgument::REQUIRED, 'Migration class name, e.g. Create_Reports_Table, Bump_Version, or create-reports-table.')
			->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace for the generated migration class.')
			->addOption('path', null, InputOption::VALUE_REQUIRED, 'Directory where the migration class should be written.')
			->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Database provider file to update when it exists.')
			->addOption('id', null, InputOption::VALUE_REQUIRED, 'Stable migration identifier.')
			->addOption('table-class', null, InputOption::VALUE_REQUIRED, 'Table class or base name used by a table-backed migration.')
			->addOption('table-namespace', null, InputOption::VALUE_REQUIRED, 'Namespace containing the table class.')
			->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite the file if it already exists.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$this->validateExplicitProviderUpdate($input);
			$file = $this->generatedFile($input);
			$this->fileWriter->write($file, (bool) $input->getOption('force'));
			$providerPath = $this->updateProvider($input);
		} catch (RuntimeException $exception) {
			$output->writeln('<error>' . $exception->getMessage() . '</error>');

			return Command::FAILURE;
		}

		$output->writeln(sprintf('<info>Created:</info> %s', $file->relativePath));
		$output->writeln('');
		$output->writeln('<comment>Register this migration with DatabaseProvider::MIGRATIONS using mergeArrayVar().</comment>');

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

	private function generatedFile(InputInterface $input): GeneratedFile {
		$className = $this->classNameResolver->className((string) $input->getArgument('name'));
		$project   = $this->autoloadResolver->project();
		$namespace = $this->namespace($input, $project->defaultPsr4Namespace());
		$path      = $this->path($input, $namespace, $project);
		$relative  = $this->relativePath($path . '/' . $className . '.php');
		$id        = $this->optionOrDefault($input, 'id', $this->classNameResolver->migrationId($className));

		if ($this->isTableMigration($input, $className)) {
			$stub           = $this->stubResolver->resolve('database', 'table-migration', DatabaseStubPath::tableMigration());
			$tableNamespace = $this->tableNamespace($input, $project->defaultPsr4Namespace());
			$tableClass     = $this->tableClass($input, $className);

			return new GeneratedFile(
				path: $path . '/' . $className . '.php',
				relativePath: $relative,
				contents: $this->stubRenderer->render($stub, [
					'namespace'                        => $namespace,
					'class'                            => $className,
					'id_php'                           => $this->phpString($id),
					'table_class'                      => $tableClass,
					'table_namespace'                  => $tableNamespace,
					'foundation_database_migration'    => $project->foundationClass('StellarWP\\Foundation\\Database\\Contracts\\Migration'),
					'foundation_database_schema'       => $project->foundationClass('StellarWP\\Foundation\\Database\\Contracts\\Schema'),
					'foundation_database_create_table' => $project->foundationClass('StellarWP\\Foundation\\Database\\Table\\CreateTable'),
				])
			);
		}

		$stub = $this->stubResolver->resolve('database', 'migration', DatabaseStubPath::migration());

		return new GeneratedFile(
			path: $path . '/' . $className . '.php',
			relativePath: $relative,
			contents: $this->stubRenderer->render($stub, [
				'namespace'                                  => $namespace,
				'class'                                      => $className,
				'id_php'                                     => $this->phpString($id),
				'foundation_database_migration'              => $project->foundationClass('StellarWP\\Foundation\\Database\\Contracts\\Migration'),
				'foundation_database_schema'                 => $project->foundationClass('StellarWP\\Foundation\\Database\\Contracts\\Schema'),
				'foundation_database_irreversible_migration' => $project->foundationClass('StellarWP\\Foundation\\Database\\Exceptions\\IrreversibleMigration'),
			])
		);
	}

	private function validateExplicitProviderUpdate(InputInterface $input): void {
		if (! $this->hasExplicitProvider($input)) {
			return;
		}

		$project      = $this->autoloadResolver->project();
		$className    = $this->classNameResolver->className((string) $input->getArgument('name'));
		$namespace    = $this->namespace($input, $project->defaultPsr4Namespace());
		$providerPath = $this->providerPath($input, $project);

		if (! is_file($providerPath)) {
			throw new RuntimeException(sprintf('Could not update database provider "%s": file does not exist.', $this->relativePath($providerPath)));
		}

		$status = $this->providerUpdater->checkMigration($providerPath, $className, $namespace);

		if ($status === ProviderRegistrationEditor::UPDATED || $status === ProviderRegistrationEditor::ALREADY_REGISTERED) {
			return;
		}

		throw new RuntimeException(sprintf(
			'Could not update database provider "%s": %s.',
			$this->relativePath($providerPath),
			$this->providerUpdateFailure($status)
		));
	}

	private function updateProvider(InputInterface $input): ?string {
		$project      = $this->autoloadResolver->project();
		$className    = $this->classNameResolver->className((string) $input->getArgument('name'));
		$namespace    = $this->namespace($input, $project->defaultPsr4Namespace());
		$providerPath = $this->providerPath($input, $project);
		$explicit     = $this->hasExplicitProvider($input);

		if (! is_file($providerPath)) {
			if ($explicit) {
				throw new RuntimeException(sprintf('Could not update database provider "%s": file does not exist.', $this->relativePath($providerPath)));
			}

			return null;
		}

		$status = $this->providerUpdater->addMigration($providerPath, $className, $namespace);

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

		return null;
	}

	private function isTableMigration(InputInterface $input, string $className): bool {
		$tableClass = $input->getOption('table-class');

		if (is_string($tableClass) && trim($tableClass) !== '') {
			return true;
		}

		return preg_match('/^Create_.*_Table$/', $className) === 1;
	}

	private function tableClass(InputInterface $input, string $migrationClass): string {
		$tableClass = $input->getOption('table-class');

		if (is_string($tableClass) && trim($tableClass) !== '') {
			return $this->classNameResolver->tableClass($tableClass);
		}

		$name = (string) preg_replace('/^Create_?/', '', $migrationClass);

		return $this->classNameResolver->tableClass($name);
	}

	private function optionOrDefault(InputInterface $input, string $option, string $default): string {
		$value = $input->getOption($option);

		if (is_string($value) && trim($value) !== '') {
			return trim($value);
		}

		return $default;
	}

	private function phpString(string $value): string {
		return var_export($value, true);
	}

	private function namespace(InputInterface $input, Psr4Namespace $autoload): string {
		$namespace = $input->getOption('namespace');

		if (is_string($namespace) && trim($namespace) !== '') {
			return $this->validNamespace(trim($namespace, '\\'));
		}

		return trim($autoload->namespace, '\\') . '\\Database\\Migrations';
	}

	private function tableNamespace(InputInterface $input, Psr4Namespace $autoload): string {
		$namespace = $input->getOption('table-namespace');

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

	private function providerUpdateFailure(string $status): string {
		return match ($status) {
			ProviderRegistrationEditor::NOT_FOUND        => 'file does not exist or is not readable',
			ProviderRegistrationEditor::NOT_WRITABLE     => 'file is not writable',
			ProviderRegistrationEditor::MISSING_ANCHOR   => 'file does not contain a generated database provider registration point',
			ProviderRegistrationEditor::MISSING_MARKER   => 'file does not contain the generated database provider markers',
			ProviderRegistrationEditor::IMPORT_COLLISION => 'a different imported class uses the same short class name',
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
			return 'this migration uses Foundation Database classes, but the Foundation runtime package is only in require-dev. Move stellarwp/foundation-database or stellarwp/foundation to require before shipping this migration.';
		}

		return 'this migration uses Foundation Database classes. Run composer require stellarwp/foundation-database, or require stellarwp/foundation, before shipping this migration.';
	}

	/**
	 * @param array<string,mixed> $dependencies
	 */
	private function hasFoundationRuntimeDependency(array $dependencies): bool {
		return array_key_exists('stellarwp/foundation-database', $dependencies)
			|| array_key_exists('stellarwp/foundation', $dependencies);
	}
}
