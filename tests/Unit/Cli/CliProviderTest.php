<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli;

use Adbar\Dot;
use lucatume\DI52\Container as DI52Container;
use StellarWP\ContainerContract\ContainerInterface;
use StellarWP\Foundation\Cli\Application;
use StellarWP\Foundation\Cli\CliProvider;
use StellarWP\Foundation\Cli\Commands\Package\Contracts\PackageRepositoryCreator;
use StellarWP\Foundation\Cli\Commands\Package\CreateCommand;
use StellarWP\Foundation\Cli\Commands\Package\GitHubPackageRepositoryCreator;
use StellarWP\Foundation\Cli\Commands\Package\PackageResolver;
use StellarWP\Foundation\Cli\Commands\Package\PackageScaffolder;
use StellarWP\Foundation\Container\ContainerAdapter;
use StellarWP\Foundation\Container\Contracts\Container;
use StellarWP\Foundation\Tests\TestCase;

final class CliProviderTest extends TestCase
{
	public function test_it_registers_cli_services(): void {
		$container = new ContainerAdapter(new DI52Container());
		$container->bind(Container::class, $container);
		$container->bind(ContainerInterface::class, $container);
		$container->singleton(Dot::class, new Dot());
		$container->register(CliProvider::class);

		$this->assertInstanceOf(Application::class, $container->get(Application::class));
		$this->assertInstanceOf(CreateCommand::class, $container->get(CreateCommand::class));
		$this->assertInstanceOf(PackageResolver::class, $container->get(PackageResolver::class));
		$this->assertInstanceOf(PackageScaffolder::class, $container->get(PackageScaffolder::class));
		$this->assertInstanceOf(GitHubPackageRepositoryCreator::class, $container->get(PackageRepositoryCreator::class));
		$this->assertTrue($container->get(Application::class)->has('package:create'));
	}
}
