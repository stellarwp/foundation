<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Generation;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use StellarWP\Foundation\Cli\Generation\GeneratorLocationResolver;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ComposerProject;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use StellarWP\Foundation\Cli\Generation\ValueObjects\Psr4Namespace;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Tests\TestCase;

final class GeneratorLocationResolverTest extends TestCase
{
	/**
	 * @param array<string, mixed> $config
	 *
	 * @dataProvider invalidConfigurations
	 */
	#[DataProvider('invalidConfigurations')]
	public function test_it_reports_invalid_project_settings(array $config, string $message): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);

		$locations = new GeneratorLocationResolver(new ProjectDirectory('/project'), new ArrayConfiguration($config));
		$locations->namespaceFor('wpcli-command', 'Cli\\Commands', new ComposerProject([new Psr4Namespace('Plugin\\', 'src')], null));
	}

	/**
	 * Provide malformed settings and the configuration key reported to the developer.
	 *
	 * @return list<array{array<string, mixed>, string}>
	 */
	public static function invalidConfigurations(): array {
		return [
			[['generators' => 'invalid'], '"generators" setting'],
			[['generators' => ['wpcli-command' => 'invalid']], 'generators.wpcli-command.namespace'],
			[['generators' => ['wpcli-command' => ['namespace' => 123]]], 'generators.wpcli-command.namespace'],
			[['generators' => ['wpcli-command' => ['namespace' => '']]], 'Invalid "generators.wpcli-command.namespace"'],
			[['generators' => ['wpcli-command' => ['namespace' => 'Bad/Namespace']]], 'Invalid "generators.wpcli-command.namespace"'],
		];
	}
}
