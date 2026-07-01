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
 * Generates a database feature provider for registering Foundation Database classes.
 *
 * Use this before generating database tables or migrations when a consuming
 * project wants a single provider where generated database classes are wired.
 */
final class ProviderCommand extends Command
{
	private const string NAME = 'make:database-provider';

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
		$this->setDescription('Generate a Foundation database provider class.')
			->addArgument('name', InputArgument::OPTIONAL, 'Provider class name, e.g. Provider or Database_Provider.', 'Provider')
			->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace for the generated provider class.')
			->addOption('path', null, InputOption::VALUE_REQUIRED, 'Directory where the provider class should be written.')
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
		$output->writeln('<comment>Register this provider in your application provider list before adding generated tables and migrations.</comment>');

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
		$stub      = $this->stubResolver->resolve('database', 'provider', DatabaseStubPath::provider());
		$relative  = $this->relativePath($path . '/' . $className . '.php');

		return new GeneratedFile(
			path: $path . '/' . $className . '.php',
			relativePath: $relative,
			contents: $this->stubRenderer->render($stub, [
				'namespace'                    => $namespace,
				'class'                        => $className,
				'foundation_database_provider' => $project->foundationClass('StellarWP\\Foundation\\Database\\DatabaseProvider'),
				'foundation_service_provider'  => $project->foundationClass('StellarWP\\Foundation\\Container\\Contracts\\Provider'),
			])
		);
	}

	private function namespace(InputInterface $input, Psr4Namespace $autoload): string {
		$namespace = $input->getOption('namespace');

		if (is_string($namespace) && trim($namespace) !== '') {
			return $this->validNamespace(trim($namespace, '\\'));
		}

		return trim($autoload->namespace, '\\') . '\\Database';
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
			return 'this provider uses Foundation Database classes, but the Foundation runtime package is only in require-dev. Move stellarwp/foundation-database or stellarwp/foundation to require before shipping this provider.';
		}

		return 'this provider uses Foundation Database classes. Run composer require stellarwp/foundation-database, or require stellarwp/foundation, before shipping this provider.';
	}

	/**
	 * @param array<string,mixed> $dependencies
	 */
	private function hasFoundationRuntimeDependency(array $dependencies): bool {
		return array_key_exists('stellarwp/foundation-database', $dependencies)
			|| array_key_exists('stellarwp/foundation', $dependencies);
	}
}
