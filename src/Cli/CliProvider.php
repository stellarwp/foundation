<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli;

use PhpParser\Lexer;
use PhpParser\ParserFactory;
use StellarWP\Foundation\Cli\Commands\Make\Database\Factories\MigrationFileFactory;
use StellarWP\Foundation\Cli\Commands\Make\Database\MigrationCommand;
use StellarWP\Foundation\Cli\Commands\Make\Database\ProviderCommand;
use StellarWP\Foundation\Cli\Commands\Make\Database\ProviderRegistrationEditor;
use StellarWP\Foundation\Cli\Commands\Make\Database\TableCommand;
use StellarWP\Foundation\Cli\Commands\Make\WPCliCommand;
use StellarWP\Foundation\Cli\Commands\Package\Contracts\PackageRepositoryCreator;
use StellarWP\Foundation\Cli\Commands\Package\CreateCommand;
use StellarWP\Foundation\Cli\Commands\Package\GitHubPackageRepositoryCreator;
use StellarWP\Foundation\Cli\Commands\Package\PackageFilesValidator;
use StellarWP\Foundation\Cli\Commands\Package\PackageRepositoryPlanFactory;
use StellarWP\Foundation\Cli\Commands\Package\PackageResolver;
use StellarWP\Foundation\Cli\Commands\Package\PackageScaffolder;
use StellarWP\Foundation\Cli\Generation\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
use StellarWP\Foundation\Cli\Generation\Php\PhpSourceEditor;
use StellarWP\Foundation\Cli\Generation\StubRenderer;
use StellarWP\Foundation\Cli\Generation\StubResolver;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use StellarWP\Foundation\Cli\Generation\WordPressClassNameResolver;
use StellarWP\Foundation\Cli\Process\Contracts\ProcessRunner;
use StellarWP\Foundation\Cli\Process\ShellProcessRunner;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Contracts\Resolver as C;

/**
 * Registers the default Foundation CLI application and command dependencies.
 *
 * Include this provider when booting the `foundation` executable so command
 * slices can be autowired through the Foundation container.
 */
final class CliProvider extends Provider
{
	private const string ROOT_PATH = self::class . '.root_path';

	/**
	 * Register the CLI application and every built-in command feature.
	 */
	public function register(): void {
		$this->registerRootPath();
		$this->registerProcess();
		$this->registerGeneration();
		$this->registerPackageCommand();
		$this->registerDatabaseCommands();
		$this->registerWpCliCommand();
		$this->registerApplication();
	}

	/**
	 * Register the consuming project directory used by generator commands.
	 */
	private function registerRootPath(): void {
		$this->container->singleton(self::ROOT_PATH, getcwd() ?: dirname(__DIR__, 2));
	}

	/**
	 * Register process execution used by repository maintenance commands.
	 */
	private function registerProcess(): void {
		$this->container->singleton(ShellProcessRunner::class);
		$this->container->bind(ProcessRunner::class, ShellProcessRunner::class);
	}

	/**
	 * Register shared source generation and Composer discovery services.
	 */
	private function registerGeneration(): void {
		$this->container->when(ProjectDirectory::class)
			->needs('$path')
			->give(static fn (C $c): string => $c->get(self::ROOT_PATH));

		$this->container->singleton(WordPressClassNameResolver::class);
		$this->container->singleton(ComposerAutoloadResolver::class);
		$this->container->singleton(GeneratedFileWriter::class);
		$this->container->singleton(Lexer::class);
		$this->container->singleton(ParserFactory::class);
		$this->container->singleton(PhpSourceEditor::class);
		$this->container->singleton(ProjectDirectory::class);
		$this->container->singleton(StubRenderer::class);
		$this->container->singleton(StubResolver::class);
	}

	/**
	 * Register the split-package creation command and its collaborators.
	 */
	private function registerPackageCommand(): void {
		$this->container->when(PackageResolver::class)
			->needs('$rootPath')
			->give(static fn (C $c): string => $c->get(self::ROOT_PATH));

		$this->container->when(PackageScaffolder::class)
			->needs('$rootPath')
			->give(static fn (C $c): string => $c->get(self::ROOT_PATH));

		$this->container->singleton(PackageResolver::class);
		$this->container->singleton(PackageScaffolder::class);
		$this->container->singleton(PackageFilesValidator::class);
		$this->container->singleton(PackageRepositoryPlanFactory::class);
		$this->container->bind(PackageRepositoryCreator::class, GitHubPackageRepositoryCreator::class);
		$this->container->singleton(CreateCommand::class);
	}

	/**
	 * Register database provider, table, and migration generator commands.
	 */
	private function registerDatabaseCommands(): void {
		$this->container->singleton(MigrationCommand::class);
		$this->container->singleton(MigrationFileFactory::class);
		$this->container->singleton(ProviderCommand::class);
		$this->container->singleton(ProviderRegistrationEditor::class);
		$this->container->singleton(TableCommand::class);
	}

	/**
	 * Register the WP-CLI command generator.
	 */
	private function registerWpCliCommand(): void {
		$this->container->singleton(WPCliCommand::class);
	}

	/**
	 * Register the console application with its ordered command list.
	 */
	private function registerApplication(): void {
		$this->container->when(Application::class)
			->needs('$commands')
			->give(static fn (C $c): array => [
				$c->get(CreateCommand::class),
				$c->get(MigrationCommand::class),
				$c->get(ProviderCommand::class),
				$c->get(TableCommand::class),
				$c->get(WPCliCommand::class),
			]);

		$this->container->singleton(Application::class);
	}
}
