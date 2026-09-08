<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Tooling;

use PHPUnit\Framework\Attributes\DataProvider;
use Plugin\Tooling\Tooling_Provider;
use RuntimeException;
use stdClass;
use StellarWP\Foundation\Cli\CliProvider;
use StellarWP\Foundation\Cli\Composer\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use StellarWP\Foundation\Cli\Tooling\ToolingProviderResolver;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Tests\TestCase;

final class ToolingProviderResolverTest extends TestCase
{
	private string $root;
	private string $fixture;

	protected function setUp(): void {
		parent::setUp();
		$this->root    = $this->prepare_temp_dir('tooling-provider');
		$this->fixture = dirname(__DIR__, 3) . '/Support/Fixtures/Cli/custom-generator';
		require_once $this->fixture . '/src/Tooling/Tooling_Provider.php';
		file_put_contents($this->root . '/composer.json', json_encode([
			'autoload' => ['psr-4' => ['Plugin\\' => $this->fixture . '/src']],
		]));
	}

	public function test_it_selects_prerequisites_before_the_project_provider_once_per_class(): void {
		$this->assertSame([CliProvider::class, Tooling_Provider::class], $this->resolver([
			'cli' => ['providers' => [Tooling_Provider::class, CliProvider::class, Tooling_Provider::class]],
		])->providers());
	}

	public function test_it_resolves_an_explicit_file_through_development_mappings(): void {
		file_put_contents($this->root . '/composer.json', json_encode([
			'autoload'     => ['psr-4' => ['Absent\\' => 'missing', 'Unrelated\\' => '.']],
			'autoload-dev' => ['psr-4' => ['Plugin\\' => [$this->fixture . '/src']]],
		]));
		$this->assertSame([Tooling_Provider::class], $this->resolver([
			'cli' => ['tooling_provider_path' => $this->fixture . '/src/Tooling/Tooling_Provider.php'],
		])->providers());
	}

	public function test_no_manifest_or_namespace_or_project_provider_is_optional(): void {
		unlink($this->root . '/composer.json');
		$this->assertSame([], $this->resolver()->providers());
		file_put_contents($this->root . '/composer.json', '{}');
		$this->assertSame([], $this->resolver()->providers());
		file_put_contents($this->root . '/composer.json', '{"autoload":{"psr-4":{"Absent\\\\":"src"}}}');
		$this->assertSame([], $this->resolver()->providers());
	}

	public function test_it_reports_invalid_composer_metadata(): void {
		file_put_contents($this->root . '/composer.json', 'invalid');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not parse composer.json');
		$this->resolver()->providers();
	}

	public function test_it_reports_malformed_psr4_mappings(): void {
		file_put_contents($this->root . '/composer.json', '{"autoload":{"psr-4":"invalid"}}');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('autoload.psr-4');
		$this->resolver()->providers();
	}

	public function test_it_rejects_an_existing_file_outside_the_composer_mappings(): void {
		file_put_contents($this->root . '/unmapped.php', '<?php');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Composer PSR-4 mappings');
		$this->resolver(['cli' => ['tooling_provider_path' => 'unmapped.php']])->providers();
	}

	/**
	 * @param array<string, mixed> $config
	 *
	 * @dataProvider invalidConfiguration
	 */
	#[DataProvider('invalidConfiguration')]
	public function test_it_reports_invalid_explicit_configuration(array $config, string $message): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);
		$this->resolver($config)->providers();
	}

	/**
	 * Supply malformed provider selections with the relevant diagnostic.
	 *
	 * @return list<array{array<string, mixed>, string}>
	 */
	public static function invalidConfiguration(): array {
		return [
			[['cli' => ['providers' => null]], 'cli.providers'],
			[['cli' => ['providers' => ['named' => CliProvider::class]]], 'cli.providers'],
			[['cli' => ['providers' => [42]]], 'position 0'],
			[['cli' => ['providers' => [stdClass::class]]], 'concrete Foundation provider'],
			[['cli' => ['providers' => [Provider::class]]], 'concrete Foundation provider'],
			[['cli' => ['tooling_provider_path' => null]], 'nonempty file path'],
			[['cli' => ['tooling_provider_path' => ' ']], 'nonempty file path'],
			[['cli' => ['tooling_provider_path' => 'missing.php']], 'readable PHP file'],
		];
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function resolver(array $config = []): ToolingProviderResolver {
		$directory = new ProjectDirectory($this->root);

		return new ToolingProviderResolver($directory, new ComposerAutoloadResolver($directory), new ArrayConfiguration($config));
	}
}
