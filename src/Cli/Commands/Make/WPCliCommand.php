<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Make;

use RuntimeException;
use StellarWP\Foundation\Cli\Generation\AutoloadNamespace;
use StellarWP\Foundation\Cli\Generation\ClassNameResolver;
use StellarWP\Foundation\Cli\Generation\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\GeneratedFile;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
use StellarWP\Foundation\Cli\Generation\StubRenderer;
use StellarWP\Foundation\Cli\Generation\StubResolver;
use StellarWP\Foundation\WPCli\WPCliStubs;
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
		private readonly ClassNameResolver $classNameResolver,
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

		return Command::SUCCESS;
	}

	private function generatedFile(InputInterface $input): GeneratedFile {
		$className   = $this->classNameResolver->commandClass((string) $input->getArgument('name'));
		$autoload    = $this->autoloadResolver->firstPsr4Namespace();
		$namespace   = $this->namespace($input, $autoload);
		$path        = $this->path($input, $namespace, $autoload);
		$stub        = $this->stubResolver->resolve('wpcli', 'command', WPCliStubs::command());
		$relative    = $this->relativePath($path . '/' . $className . '.php');
		$description = (string) ($input->getOption('description') ?: $this->classNameResolver->description($className));
		$subcommand  = (string) ($input->getOption('subcommand') ?: $this->classNameResolver->subcommand($className));

		return new GeneratedFile(
			path: $path . '/' . $className . '.php',
			relativePath: $relative,
			contents: $this->stubRenderer->render($stub, [
				'namespace'                => $namespace,
				'class'                    => $className,
				'foundation_wpcli_command' => $this->foundationClass('StellarWP\\Foundation\\WPCli\\Command'),
				'subcommand'               => $subcommand,
				'description'              => $description,
			])
		);
	}

	private function foundationClass(string $class): string {
		return ($this->autoloadResolver->straussNamespacePrefix() ?? '') . $class;
	}

	private function namespace(InputInterface $input, AutoloadNamespace $autoload): string {
		$namespace = $input->getOption('namespace');

		if (is_string($namespace) && trim($namespace) !== '') {
			return trim($namespace, '\\');
		}

		return trim($autoload->namespace, '\\') . '\\Cli\\Commands';
	}

	private function path(InputInterface $input, string $namespace, AutoloadNamespace $autoload): string {
		$path = $input->getOption('path');

		if (is_string($path) && trim($path) !== '') {
			return $this->absolutePath($path);
		}

		$autoloadNamespace = trim($autoload->namespace, '\\');
		$relativeNamespace = '';

		if (str_starts_with($namespace, $autoloadNamespace)) {
			$relativeNamespace = trim(substr($namespace, strlen($autoloadNamespace)), '\\');
		}

		$segments = $relativeNamespace === '' ? '' : '/' . str_replace('\\', '/', $relativeNamespace);

		return $this->rootPath . '/' . trim($autoload->path, '/') . $segments;
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
}
