<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli;

use StellarWP\Foundation\Cli\Application;
use StellarWP\Foundation\Cli\CliProvider;
use StellarWP\Foundation\Cli\Commands\Make\Database\Factories\MigrationFileFactory;
use StellarWP\Foundation\Cli\Commands\Make\Database\MigrationCommand;
use StellarWP\Foundation\Cli\Commands\Make\Database\ProviderCommand;
use StellarWP\Foundation\Cli\Commands\Make\Database\TableCommand;
use StellarWP\Foundation\Cli\Commands\Make\WPCliCommand;
use StellarWP\Foundation\Cli\Composer\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\GeneratedFileWriter;
use StellarWP\Foundation\Cli\Generation\StubRenderer;
use StellarWP\Foundation\Cli\Generation\StubResolver;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use StellarWP\Foundation\Cli\Generation\WordPressClassNameResolver;
use StellarWP\Foundation\Cli\Process\Contracts\ProcessRunner;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Dev\Cli\MonorepoProvider;
use StellarWP\Foundation\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CliProviderTest extends TestCase
{
	public function test_maintenance_registration_is_idempotent_and_preserves_the_process_runner(): void {
		$container = (new ContainerFactory())->create(new ArrayConfiguration());
		$container->register(CliProvider::class);
		$runner = $this->createMock(ProcessRunner::class);
		$runner->expects($this->never())->method('run');
		$container->singleton(ProcessRunner::class, $runner);
		$container->register(MonorepoProvider::class);
		$container->register(MonorepoProvider::class);

		$command = new CommandTester($container->get(Application::class)->find('package:create'));
		$this->assertSame(0, $command->execute(['package' => 'Log'], ['interactive' => false]));
		$this->assertStringContainsString('stellarwp/foundation-log', $command->getDisplay());
		$this->assertSame($runner, $container->get(ProcessRunner::class));
	}

	public function test_it_registers_cli_services(): void {
		$container = (new ContainerFactory())->create(new ArrayConfiguration());
		$container->register(CliProvider::class);
		$container->register(CliProvider::class);

		$this->assertInstanceOf(Application::class, $container->get(Application::class));
		$this->assertInstanceOf(MigrationCommand::class, $container->get(MigrationCommand::class));
		$this->assertInstanceOf(MigrationFileFactory::class, $container->get(MigrationFileFactory::class));
		$this->assertInstanceOf(ProviderCommand::class, $container->get(ProviderCommand::class));
		$this->assertInstanceOf(TableCommand::class, $container->get(TableCommand::class));
		$this->assertInstanceOf(WPCliCommand::class, $container->get(WPCliCommand::class));
		$this->assertInstanceOf(WordPressClassNameResolver::class, $container->get(WordPressClassNameResolver::class));
		$this->assertInstanceOf(ComposerAutoloadResolver::class, $container->get(ComposerAutoloadResolver::class));
		$this->assertInstanceOf(GeneratedFileWriter::class, $container->get(GeneratedFileWriter::class));
		$this->assertInstanceOf(StubRenderer::class, $container->get(StubRenderer::class));
		$this->assertInstanceOf(StubResolver::class, $container->get(StubResolver::class));
		$this->assertSame(getcwd(), $container->get(ProjectDirectory::class)->path);
		$this->assertFalse($container->get(Application::class)->has('package:create'));
		$this->assertTrue($container->get(Application::class)->has('make:database-migration'));
		$this->assertTrue($container->get(Application::class)->has('make:database-provider'));
		$this->assertTrue($container->get(Application::class)->has('make:database-table'));
		$this->assertTrue($container->get(Application::class)->has('make:wpcli-command'));
	}
}
