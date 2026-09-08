<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Composer;

use JsonException;
use RuntimeException;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ComposerProject;
use StellarWP\Foundation\Cli\Generation\ValueObjects\PhpNamespace;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use StellarWP\Foundation\Cli\Generation\ValueObjects\Psr4Namespace;
use StellarWP\Foundation\Cli\Generation\ValueObjects\StraussConfig;

/**
 * Reads project Composer mappings for class generation and tooling provider lookup.
 *
 * @internal
 */
final readonly class ComposerAutoloadResolver
{
	/**
	 * Receive the project directory containing composer.json.
	 */
	public function __construct(
		private ProjectDirectory $projectDirectory
	) {
	}

	/**
	 * Resolve runtime generation namespaces and Strauss settings.
	 *
	 * @throws RuntimeException When Composer metadata or its runtime mappings are invalid.
	 */
	public function project(): ComposerProject {
		$composer = $this->composer();

		if (($composer['autoload']['psr-4'] ?? []) === []) {
			throw new RuntimeException('Could not find an autoload.psr-4 namespace in composer.json.');
		}
		$psr4Namespaces = $this->mappings($composer, false);

		return new ComposerProject(
			psr4Namespaces: $psr4Namespaces,
			strauss: $this->straussConfig($composer)
		);
	}

	/**
	 * Read the project's PSR-4 mappings, optionally including development classes.
	 *
	 * @throws RuntimeException When Composer metadata or a namespace is invalid.
	 *
	 * @return list<Psr4Namespace>
	 */
	public function namespaces(bool $includeDevelopment = false): array {
		return $this->mappings($this->composer(), $includeDevelopment);
	}

	/**
	 * @param array<string, mixed> $composer
	 *
	 * @return list<Psr4Namespace>
	 */
	private function mappings(array $composer, bool $includeDevelopment): array {
		$namespaces = [];

		foreach ($includeDevelopment ? ['autoload', 'autoload-dev'] : ['autoload'] as $section) {
			$psr4 = $composer[$section]['psr-4'] ?? [];

			if (! is_array($psr4)) {
				throw new RuntimeException(sprintf('The %s.psr-4 setting in composer.json must be an array.', $section));
			}

			foreach ($psr4 as $namespace => $paths) {
				if (! is_string($namespace) || $namespace === '') {
					continue;
				}

				$namespace = (new PhpNamespace(trim($namespace, '\\')))->value . '\\';
				foreach ($this->paths($paths) as $path) {
					$namespaces[] = new Psr4Namespace($namespace, $path);
				}
			}
		}

		return $namespaces;
	}

	/**
	 * Read the default runtime namespace used by application generators.
	 *
	 * @throws RuntimeException When the project has no valid runtime mapping.
	 */
	public function firstPsr4Namespace(): Psr4Namespace {
		return $this->project()->defaultPsr4Namespace();
	}

	/**
	 * Read the configured prefix for generated Foundation imports.
	 *
	 * @throws RuntimeException When Composer metadata or the prefix is invalid.
	 */
	public function straussNamespacePrefix(): ?string {
		return $this->straussConfig($this->composer())?->namespacePrefix;
	}

	/**
	 * @return list<string>
	 */
	private function paths(mixed $paths): array {
		$paths = is_array($paths) ? $paths : [$paths];

		return array_values(array_filter($paths, static fn (mixed $path): bool => is_string($path)));
	}

	/**
	 * @param array<string,mixed> $composer
	 */
	private function straussConfig(array $composer): ?StraussConfig {
		$prefix = $composer['extra']['strauss']['namespace_prefix'] ?? null;

		if (! is_string($prefix) || trim($prefix, '\\') === '') {
			return null;
		}

		return new StraussConfig((new PhpNamespace(trim($prefix, '\\')))->value . '\\');
	}

	/**
	 * @return array<string,mixed>
	 */
	private function composer(): array {
		$composerPath = $this->projectDirectory->absolutePath('composer.json');

		if (! file_exists($composerPath)) {
			throw new RuntimeException(sprintf('Could not find composer.json at "%s".', $composerPath));
		}

		try {
			$composer = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new RuntimeException(sprintf('Could not parse composer.json at "%s": %s', $composerPath, $exception->getMessage()), 0, $exception);
		}

		if (! is_array($composer)) {
			throw new RuntimeException(sprintf('Could not read composer.json at "%s".', $composerPath));
		}

		return $composer;
	}
}
