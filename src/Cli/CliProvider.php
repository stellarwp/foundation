<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli;

use lucatume\DI52\Container;
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
use StellarWP\Foundation\Cli\Generation\StubRenderer;
use StellarWP\Foundation\Cli\Generation\StubResolver;
use StellarWP\Foundation\Cli\Generation\WordPressClassNameResolver;
use StellarWP\Foundation\Cli\Process\Contracts\ProcessRunner;
use StellarWP\Foundation\Cli\Process\ShellProcessRunner;
use StellarWP\Foundation\Container\Contracts\Provider;

/**
 * Registers the default Foundation CLI application and command dependencies.
 *
 * Include this provider when booting the `foundation` executable so command
 * slices can be autowired through the Foundation container.
 */
final class CliProvider extends Provider
{
	public const string ROOT_PATH = 'foundation.cli.root_path';

	public function register(): void {
		$this->container->singleton(self::ROOT_PATH, getcwd() ?: dirname(__DIR__, 2));

		$this->container->when(PackageResolver::class)
			->needs('$rootPath')
			->give(static fn (Container $c): string => $c->get(self::ROOT_PATH));

		$this->container->when(PackageScaffolder::class)
			->needs('$rootPath')
			->give(static fn (Container $c): string => $c->get(self::ROOT_PATH));

		$this->container->when(ComposerAutoloadResolver::class)
			->needs('$rootPath')
			->give(static fn (Container $c): string => $c->get(self::ROOT_PATH));

		$this->container->when(StubResolver::class)
			->needs('$rootPath')
			->give(static fn (Container $c): string => $c->get(self::ROOT_PATH));

		$this->container->when(WPCliCommand::class)
			->needs('$rootPath')
			->give(static fn (Container $c): string => $c->get(self::ROOT_PATH));

		$this->container->when(Application::class)
			->needs('$commands')
			->give(static fn (Container $c): array => [
				$c->get(CreateCommand::class),
				$c->get(WPCliCommand::class),
			]);

		$this->container->singleton(PackageResolver::class);
		$this->container->singleton(PackageScaffolder::class);
		$this->container->singleton(PackageFilesValidator::class);
		$this->container->singleton(PackageRepositoryPlanFactory::class);
		$this->container->singleton(ShellProcessRunner::class);
		$this->container->bind(ProcessRunner::class, ShellProcessRunner::class);
		$this->container->bind(PackageRepositoryCreator::class, GitHubPackageRepositoryCreator::class);
		$this->container->singleton(CreateCommand::class);
		$this->container->singleton(WordPressClassNameResolver::class);
		$this->container->singleton(ComposerAutoloadResolver::class);
		$this->container->singleton(GeneratedFileWriter::class);
		$this->container->singleton(StubRenderer::class);
		$this->container->singleton(StubResolver::class);
		$this->container->singleton(WPCliCommand::class);
		$this->container->singleton(Application::class);
	}
}
