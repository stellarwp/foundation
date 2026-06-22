<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation;

use JsonException;
use RuntimeException;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ComposerProject;
use StellarWP\Foundation\Cli\Generation\ValueObjects\Psr4Namespace;
use StellarWP\Foundation\Cli\Generation\ValueObjects\StraussConfig;

/**
 * Reads a project's Composer autoload configuration for generator defaults.
 *
 * Make commands use this to infer where application classes should be written
 * and which namespace they should use when the developer does not pass options.
 */
final readonly class ComposerAutoloadResolver
{
	public function __construct(
		private string $rootPath
	) {
	}

	public function project(): ComposerProject {
		$composer = $this->composer();
		$psr4     = $composer['autoload']['psr-4'] ?? [];

		if (! is_array($psr4) || $psr4 === []) {
			throw new RuntimeException('Could not find an autoload.psr-4 namespace in composer.json.');
		}

		$psr4Namespaces = [];

		foreach ($psr4 as $namespace => $paths) {
			if (! is_string($namespace) || $namespace === '') {
				continue;
			}

			foreach ($this->paths($paths) as $path) {
				$psr4Namespaces[] = new Psr4Namespace(
					namespace: trim($namespace, '\\') . '\\',
					path: trim($path, '/')
				);
			}
		}

		return new ComposerProject(
			psr4Namespaces: $psr4Namespaces,
			strauss: $this->straussConfig($composer)
		);
	}

	public function firstPsr4Namespace(): Psr4Namespace {
		return $this->project()->defaultPsr4Namespace();
	}

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

		return new StraussConfig(trim($prefix, '\\') . '\\');
	}

	/**
	 * @return array<string,mixed>
	 */
	private function composer(): array {
		$composerPath = $this->rootPath . '/composer.json';

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
