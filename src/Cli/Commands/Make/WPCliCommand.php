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
use StellarWP\Foundation\WPCli\WPCliStubPath;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates a WP-CLI command class that extends the Foundation WPCli command base.
 *
 * Use this from a consuming WordPress project to quickly create a command with
 * the expected Snake_Case class name, synopsis constants, and WP formatting.
 */
final class WPCliCommand extends Command
{
	private const string NAME = 'make:wpcli-command';

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
		$this->setDescription('Generate a WP-CLI command class that extends the Foundation command base.')
			->addArgument('name', InputArgument::REQUIRED, 'Command class name, e.g. Sync_Products_Command, SyncProducts, or sync-products.')
			->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace for the generated command class.')
			->addOption('path', null, InputOption::VALUE_REQUIRED, 'Directory where the command class should be written.')
			->addOption('subcommand', null, InputOption::VALUE_REQUIRED, 'WP-CLI subcommand name under the configured command prefix.')
			->addOption('description', null, InputOption::VALUE_REQUIRED, 'Command description shown in WP-CLI help.')
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
		$output->writeln('<comment>Register this command from your WP-CLI provider and configure its $commandPrefix container argument.</comment>');

		$runtimeDependencyWarning = $this->runtimeDependencyWarning();

		if ($runtimeDependencyWarning !== null) {
			$output->writeln('');
			$output->writeln('<error>Runtime dependency missing:</error> ' . $runtimeDependencyWarning);
		}

		return Command::SUCCESS;
	}

	private function generatedFile(InputInterface $input): GeneratedFile {
		$className   = $this->classNameResolver->commandClass((string) $input->getArgument('name'));
		$project     = $this->autoloadResolver->project();
		$namespace   = $this->namespace($input, $project->defaultPsr4Namespace());
		$path        = $this->path($input, $namespace, $project);
		$stub        = $this->stubResolver->resolve('wpcli', 'command', WPCliStubPath::command());
		$relative    = $this->relativePath($path . '/' . $className . '.php');
		$description = (string) ($input->getOption('description') ?: $this->classNameResolver->description($className));
		$subcommand  = (string) ($input->getOption('subcommand') ?: $this->classNameResolver->subcommand($className));

		return new GeneratedFile(
			path: $path . '/' . $className . '.php',
			relativePath: $relative,
			contents: $this->stubRenderer->render($stub, [
				'namespace'                => $namespace,
				'class'                    => $className,
				'foundation_wpcli_command' => $project->foundationClass('StellarWP\\Foundation\\WPCli\\Command'),
				'subcommand'               => $subcommand,
				'subcommand_php'           => $this->phpString($subcommand),
				'description'              => $description,
				'description_php'          => $this->phpString($description),
			])
		);
	}

	private function phpString(string $value): string {
		return var_export($value, true);
	}

	private function namespace(InputInterface $input, Psr4Namespace $autoload): string {
		$namespace = $input->getOption('namespace');

		if (is_string($namespace) && trim($namespace) !== '') {
			return $this->validNamespace(trim($namespace, '\\'));
		}

		return trim($autoload->namespace, '\\') . '\\Cli\\Commands';
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
			return 'this command extends Foundation WPCli classes, but the Foundation runtime package is only in require-dev. Move stellarwp/foundation-wpcli or stellarwp/foundation to require before shipping this command.';
		}

		return 'this command extends Foundation WPCli classes. Run composer require stellarwp/foundation-wpcli, or require stellarwp/foundation, before shipping this command.';
	}

	/**
	 * @param array<string,mixed> $dependencies
	 */
	private function hasFoundationRuntimeDependency(array $dependencies): bool {
		return array_key_exists('stellarwp/foundation-wpcli', $dependencies)
			|| array_key_exists('stellarwp/foundation', $dependencies);
	}
}
