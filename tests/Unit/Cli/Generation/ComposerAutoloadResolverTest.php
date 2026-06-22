<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Generation;

use RuntimeException;
use StellarWP\Foundation\Cli\Generation\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\ValueObjects\StraussConfig;
use StellarWP\Foundation\Tests\TestCase;

final class ComposerAutoloadResolverTest extends TestCase
{
	/**
	 * @var list<string>
	 */
	private array $temporaryRoots = [];

	private string $tempDir;

	protected function setUp(): void {
		parent::setUp();

		$this->tempDir = $this->prepare_temp_dir('composer-autoload-resolver');
	}

	protected function tearDown(): void {
		foreach ($this->temporaryRoots as $temporaryRoot) {
			$this->removeDirectory($temporaryRoot);
		}

		parent::tearDown();
	}

	public function test_it_resolves_the_first_psr4_namespace(): void {
		$root = $this->temporaryRoot([
			'autoload' => [
				'psr-4' => [
					'Acme\\Plugin\\' => 'src',
				],
			],
		]);

		$namespace = (new ComposerAutoloadResolver($root))->firstPsr4Namespace();

		$this->assertSame('Acme\\Plugin\\', $namespace->namespace);
		$this->assertSame('src', $namespace->path);
	}

	public function test_it_resolves_a_composer_project_with_all_psr4_namespaces_and_strauss_config(): void {
		$root = $this->temporaryRoot([
			'autoload' => [
				'psr-4' => [
					'Acme\\Plugin\\' => 'src',
					'Acme\\Shared\\' => ['shared', 'generated'],
				],
			],
			'extra'    => [
				'strauss' => [
					'namespace_prefix' => 'Acme\\Product\\',
				],
			],
		]);

		$project = (new ComposerAutoloadResolver($root))->project();
		$shared  = $project->psr4NamespaceFor('Acme\\Shared\\Cli');

		$this->assertCount(3, $project->psr4Namespaces);
		$this->assertSame('Acme\\Plugin\\', $project->defaultPsr4Namespace()->namespace);
		$this->assertNotNull($shared);
		$this->assertSame('shared', $shared->path);
		$this->assertSame('Acme\\Product\\StellarWP\\Foundation\\WPCli\\Command', $project->foundationClass('StellarWP\\Foundation\\WPCli\\Command'));
	}

	public function test_it_matches_the_most_specific_psr4_namespace(): void {
		$root = $this->temporaryRoot([
			'autoload' => [
				'psr-4' => [
					'Acme\\'         => 'src',
					'Acme\\Plugin\\' => 'app',
				],
			],
		]);

		$project = (new ComposerAutoloadResolver($root))->project();
		$match   = $project->psr4NamespaceFor('Acme\\Plugin\\Cli');

		$this->assertNotNull($match);
		$this->assertSame('Acme\\Plugin\\', $match->namespace);
		$this->assertSame('app', $match->path);
	}

	public function test_it_skips_invalid_psr4_namespace_keys(): void {
		$root = $this->temporaryRoot([
			'autoload' => [
				'psr-4' => [
					123              => 'invalid',
					'Acme\\Plugin\\' => 'src',
				],
			],
		]);

		$namespace = (new ComposerAutoloadResolver($root))->firstPsr4Namespace();

		$this->assertSame('Acme\\Plugin\\', $namespace->namespace);
		$this->assertSame('src', $namespace->path);
	}

	public function test_it_skips_non_string_psr4_array_paths(): void {
		$root = $this->temporaryRoot([
			'autoload' => [
				'psr-4' => [
					'Acme\\Plugin\\' => [false, 'src'],
				],
			],
		]);

		$namespace = (new ComposerAutoloadResolver($root))->firstPsr4Namespace();

		$this->assertSame('Acme\\Plugin\\', $namespace->namespace);
		$this->assertSame('src', $namespace->path);
	}

	public function test_it_allows_empty_psr4_paths_for_project_root_namespace(): void {
		$root = $this->temporaryRoot([
			'autoload' => [
				'psr-4' => [
					'Acme\\Plugin\\' => '',
				],
			],
		]);

		$namespace = (new ComposerAutoloadResolver($root))->firstPsr4Namespace();

		$this->assertSame('Acme\\Plugin\\', $namespace->namespace);
		$this->assertSame('', $namespace->path);
		$this->assertSame('Cli/Commands', $namespace->pathFor('Acme\\Plugin\\Cli\\Commands'));
	}

	public function test_it_ignores_empty_psr4_fallback_namespace_roots(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not find a valid autoload.psr-4 namespace in composer.json.');

		(new ComposerAutoloadResolver($this->temporaryRoot([
			'autoload' => [
				'psr-4' => [
					'' => 'src',
				],
			],
		])))->firstPsr4Namespace();
	}

	public function test_it_resolves_strauss_namespace_prefix(): void {
		$root = $this->temporaryRoot([
			'extra' => [
				'strauss' => [
					'namespace_prefix' => 'Acme\\Product\\',
				],
			],
		]);

		$this->assertSame('Acme\\Product\\', (new ComposerAutoloadResolver($root))->straussNamespacePrefix());
	}

	public function test_it_refuses_to_prefix_non_foundation_classes(): void {
		$root = $this->temporaryRoot([
			'autoload' => [
				'psr-4' => [
					'Acme\\Plugin\\' => 'src',
				],
			],
			'extra'    => [
				'strauss' => [
					'namespace_prefix' => 'Acme\\Product\\',
				],
			],
		]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot apply Foundation namespace prefix to non-Foundation class');

		(new ComposerAutoloadResolver($root))->project()->foundationClass('Acme\\Plugin\\Command');
	}

	public function test_strauss_config_refuses_to_prefix_non_foundation_classes(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot apply Foundation namespace prefix to non-Foundation class');

		(new StraussConfig('Acme\\Product\\'))->foundationClass('Acme\\Plugin\\Command');
	}

	public function test_it_returns_null_when_strauss_namespace_prefix_is_missing(): void {
		$this->assertNull((new ComposerAutoloadResolver($this->temporaryRoot([])))->straussNamespacePrefix());
	}

	public function test_it_fails_when_composer_json_is_missing(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not find composer.json');

		(new ComposerAutoloadResolver($this->temporaryRoot()))->firstPsr4Namespace();
	}

	public function test_it_fails_when_composer_json_root_is_not_an_object(): void {
		$root = $this->temporaryRoot();

		file_put_contents($root . '/composer.json', 'null');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not read composer.json');

		(new ComposerAutoloadResolver($root))->firstPsr4Namespace();
	}

	public function test_it_fails_when_composer_json_is_malformed(): void {
		$root = $this->temporaryRoot();

		file_put_contents($root . '/composer.json', '{"autoload":');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not parse composer.json');

		(new ComposerAutoloadResolver($root))->firstPsr4Namespace();
	}

	public function test_it_fails_when_composer_json_has_no_psr4_autoload(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not find an autoload.psr-4 namespace in composer.json.');

		(new ComposerAutoloadResolver($this->temporaryRoot([
			'autoload' => [],
		])))->firstPsr4Namespace();
	}

	public function test_it_fails_when_psr4_autoload_has_no_valid_path(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not find a valid autoload.psr-4 namespace in composer.json.');

		(new ComposerAutoloadResolver($this->temporaryRoot([
			'autoload' => [
				'psr-4' => [
					'Acme\\Plugin\\' => [],
				],
			],
		])))->firstPsr4Namespace();
	}

	/**
	 * @param array<string,mixed>|null $composer
	 */
	private function temporaryRoot(?array $composer = null): string {
		$root = $this->tempDir . '/foundation-autoload-test-' . bin2hex(random_bytes(8));

		if (! mkdir($root, 0777, true) && ! is_dir($root)) {
			$this->fail(sprintf('Could not create temporary root "%s".', $root));
		}

		if ($composer !== null) {
			file_put_contents($root . '/composer.json', (string) json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		}

		$this->temporaryRoots[] = $root;

		return $root;
	}

	private function removeDirectory(string $directory): void {
		if (! is_dir($directory)) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($files as $file) {
			if ($file->isDir()) {
				rmdir($file->getPathname());
			} else {
				unlink($file->getPathname());
			}
		}

		rmdir($directory);
	}
}
