<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Make;

use RuntimeException;
use StellarWP\Foundation\Cli\Generation\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
use StellarWP\Foundation\Cli\Generation\GeneratorLocationResolver;
use StellarWP\Foundation\Cli\Generation\StubRenderer;
use StellarWP\Foundation\Cli\Generation\StubResolver;
use StellarWP\Foundation\Cli\Generation\ValueObjects\GeneratedFile;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
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
	public const string CONFIG_KEY = 'wpcli-command';
	public const string NAME       = 'make:' . self::CONFIG_KEY;

	public function __construct(
		private readonly ProjectDirectory $projectDirectory,
		private readonly ComposerAutoloadResolver $autoloadResolver,
		private readonly GeneratorLocationResolver $locations,
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
		$output->writeln('<comment>Contribute this command to WPCliProvider::COMMANDS from its feature provider.</comment>');

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
		$namespace   = $this->locations->namespaceFor(self::CONFIG_KEY, $project, (string) $input->getOption('namespace'));
		$path        = $this->locations->directoryFor($namespace, $project, (string) $input->getOption('path'));
		$stub        = $this->stubResolver->resolve('wpcli', 'command', WPCliStubPath::command());
		$relative    = $this->projectDirectory->relativePath($path . '/' . $className . '.php');
		$description = (string) ($input->getOption('description') ?: $this->classNameResolver->description($className));
		$subcommand  = (string) ($input->getOption('subcommand') ?: $this->classNameResolver->subcommand($className));

		return new GeneratedFile(
			path: $path . '/' . $className . '.php',
			relativePath: $relative,
			contents: $this->stubRenderer->render($stub, [
				'namespace'                => $namespace,
				'class'                    => $className,
				'foundation_wpcli_command' => $project->foundationClass('StellarWP\\Foundation\\WPCli\\Command'),
				'subcommand_doc'           => $this->stubRenderer->phpDocLine($subcommand),
				'subcommand_php'           => $this->stubRenderer->phpStringLiteral($subcommand),
				'description_doc'          => $this->stubRenderer->phpDocLine($description),
				'description_php'          => $this->stubRenderer->phpStringLiteral($description),
			])
		);
	}

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
