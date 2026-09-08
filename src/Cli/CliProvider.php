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
use StellarWP\Foundation\Cli\Composer\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
use StellarWP\Foundation\Cli\Generation\GeneratorLocationResolver;
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
	/**
	 * Commands contributed lazily by project and package providers.
	 */
	public const string COMMANDS = self::class . '.commands';

	private const string ROOT_PATH = self::class . '.root_path';

	private bool $registered = false;

	/**
	 * Register the CLI application and every built-in command feature.
	 */
	public function register(): void {
		if ($this->registered) {
			return;
		}

		$this->registerRootPath();
		$this->registerProcess();
		$this->registerGeneration();
		$this->registerDatabaseCommands();
		$this->registerWpCliCommand();
		$this->registerApplication();
		$this->registered = true;
	}

	/**
	 * Register the consuming project directory used by generator commands.
	 */
	private function registerRootPath(): void {
		$this->container->singleton(self::ROOT_PATH, getcwd() ?: dirname(__DIR__, 2));
	}

	/**
	 * Register the default process runner for CLI commands.
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
		$this->container->singleton(GeneratorLocationResolver::class);
		$this->container->singleton(GeneratedFileWriter::class);
		$this->container->singleton(Lexer::class);
		$this->container->singleton(ParserFactory::class);
		$this->container->singleton(PhpSourceEditor::class);
		$this->container->singleton(ProjectDirectory::class);
		$this->container->singleton(StubRenderer::class);
		$this->container->singleton(StubResolver::class);
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
		$this->container->mergeArrayVar(self::COMMANDS, static fn (C $c): array => [
			$c->get(MigrationCommand::class),
			$c->get(ProviderCommand::class),
			$c->get(TableCommand::class),
		]);
	}

	/**
	 * Register the WP-CLI command generator.
	 */
	private function registerWpCliCommand(): void {
		$this->container->singleton(WPCliCommand::class);
		$this->container->mergeArrayVar(self::COMMANDS, static fn (C $c): array => [$c->get(WPCliCommand::class)]);
	}

	/**
	 * Register the console application with its ordered command list.
	 */
	private function registerApplication(): void {
		$this->container->when(Application::class)
			->needs('$commands')
			->give(static fn (C $c): array => $c->get(self::COMMANDS));

		$this->container->singleton(Application::class);
	}
}
