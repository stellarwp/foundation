<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Make;

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
 * Generates a WordPress-style table class for Foundation Database migrations.
 *
 * Use this from a consuming WordPress project when a feature needs a table
 * definition that can be wrapped in a Foundation `CreateTable` migration.
 */
final class DatabaseTableCommand extends Command
{
	private const string NAME = 'make:database-table';

	public function __construct(
		private readonly string $rootPath,
		private readonly ComposerAutoloadResolver $autoloadResolver,
		private readonly WordPressClassNameResolver $classNameResolver,
		private readonly StubResolver $stubResolver,
		private readonly StubRenderer $stubRenderer,
		private readonly GeneratedFileWriter $fileWriter
	) {
		parent::__construct(self::NAME);
	}

	protected function configure(): void {
		$this->setDescription('Generate a Foundation database table class.')
			->addArgument('name', InputArgument::REQUIRED, 'Table class name, e.g. Reports_Table, Reports, or reports.')
			->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace for the generated table class.')
			->addOption('path', null, InputOption::VALUE_REQUIRED, 'Directory where the table class should be written.')
			->addOption('id', null, InputOption::VALUE_REQUIRED, 'Stable table identifier used by migrations.')
			->addOption('table', null, InputOption::VALUE_REQUIRED, 'Unprefixed WordPress table name.')
			->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite the file if it already exists.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$file = $this->generatedFile($input);
			$this->fileWriter->write($file, (bool) $input->getOption('force'));
		} catch (RuntimeException $exception) {
			$output->writeln('<error>' . $exception->getMessage() . '</error>');

			return Command::FAILURE;
		}

		$output->writeln(sprintf('<info>Created:</info> %s', $file->relativePath));
		$output->writeln('');
		$output->writeln('<comment>Add this table to a migration, usually with StellarWP\Foundation\Database\Table\CreateTable.</comment>');

		$runtimeDependencyWarning = $this->runtimeDependencyWarning();

		if ($runtimeDependencyWarning !== null) {
			$output->writeln('');
			$output->writeln('<error>Runtime dependency missing:</error> ' . $runtimeDependencyWarning);
		}

		return Command::SUCCESS;
	}

	private function generatedFile(InputInterface $input): GeneratedFile {
		$className = $this->classNameResolver->tableClass((string) $input->getArgument('name'));
		$project   = $this->autoloadResolver->project();
		$namespace = $this->namespace($input, $project->defaultPsr4Namespace());
		$path      = $this->path($input, $namespace, $project);
		$stub      = $this->stubResolver->resolve('database', 'table', DatabaseStubPath::table());
		$relative  = $this->relativePath($path . '/' . $className . '.php');
		$table     = $this->optionOrDefault($input, 'table', $this->classNameResolver->tableName($className));
		$id        = $this->optionOrDefault($input, 'id', $table . '_table');

		return new GeneratedFile(
			path: $path . '/' . $className . '.php',
			relativePath: $relative,
			contents: $this->stubRenderer->render($stub, [
				'namespace'                            => $namespace,
				'class'                                => $className,
				'id_php'                               => $this->phpString($id),
				'table_php'                            => $this->phpString($table),
				'foundation_database_contract'         => $project->foundationClass('StellarWP\\Foundation\\Database\\Contracts\\Database'),
				'foundation_database_table'            => $project->foundationClass('StellarWP\\Foundation\\Database\\Contracts\\Table'),
				'foundation_database_table_definition' => $project->foundationClass('StellarWP\\Foundation\\Database\\Table\\TableDefinition'),
			])
		);
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
