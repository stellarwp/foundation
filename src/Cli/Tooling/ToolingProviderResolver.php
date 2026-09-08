<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Tooling;

use ReflectionClass;
use RuntimeException;
use StellarWP\Foundation\Cli\Composer\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use StellarWP\Foundation\Container\Contracts\Configuration;
use StellarWP\Foundation\Container\Contracts\Provider;

/**
 * Selects project tooling providers before the console application is constructed.
 *
 * @internal
 */
final readonly class ToolingProviderResolver
{
	/**
	 * Receive project metadata and the shared configuration snapshot.
	 */
	public function __construct(
		private ProjectDirectory $projectDirectory,
		private ComposerAutoloadResolver $autoload,
		private Configuration $config
	) {
	}

	/**
	 * Select explicit prerequisites followed by the project provider, once per class.
	 *
	 * @throws RuntimeException When a configured provider, path, or Composer mapping is invalid.
	 *
	 * @return list<class-string<Provider>>
	 */
	public function providers(): array {
		$providers = $this->config->get('cli.providers', []);

		if (! is_array($providers) || ! array_is_list($providers)) {
			throw new RuntimeException('The "cli.providers" setting in config.php must be a list of provider classes.');
		}

		$projectProvider = $this->projectProvider();

		if ($projectProvider !== null) {
			$providers[] = $projectProvider;
		}

		$selected = [];
		foreach ($providers as $index => $provider) {
			if (! is_string($provider) || ! is_a($provider, Provider::class, true) || (new ReflectionClass($provider))->isAbstract()) {
				throw new RuntimeException(sprintf('Tooling provider %s at position %d must be a concrete Foundation provider. Check cli.providers or the project tooling provider.', is_string($provider) ? $provider : get_debug_type($provider), $index));
			}

			$selected[strtolower(ltrim($provider, '\\'))] = $provider;
		}

		// The project provider runs last, even when also listed among prerequisites.
		if ($projectProvider !== null) {
			$key      = strtolower(ltrim($projectProvider, '\\'));
			$provider = $selected[$key];
			unset($selected[$key]);
			$selected[$key] = $provider;
		}

		return array_values($selected);
	}

	/**
	 * Prefer a configured file, otherwise look up the conventional project class.
	 */
	private function projectProvider(): ?string {
		if ($this->config->has('cli.tooling_provider_path')) {
			$path = $this->config->get('cli.tooling_provider_path');

			if (! is_string($path) || trim($path) === '') {
				throw new RuntimeException('The "cli.tooling_provider_path" setting in config.php must be a nonempty file path.');
			}

			return $this->providerAt($path);
		}

		if (! is_file($this->projectDirectory->absolutePath('composer.json'))) {
			return null;
		}

		$namespace = $this->autoload->namespaces()[0] ?? null;

		if ($namespace === null) {
			return null;
		}

		$provider = $namespace->namespace . 'Tooling\\Tooling_Provider';

		return class_exists($provider) ? $provider : null;
	}

	/**
	 * Resolve a provider file through the project's runtime or development PSR-4 mappings.
	 */
	private function providerAt(string $path): string {
		$file = realpath($this->projectDirectory->absolutePath($path));

		if ($file === false || ! is_file($file) || ! is_readable($file) || ! str_ends_with($file, '.php')) {
			throw new RuntimeException(sprintf('The cli.tooling_provider_path file "%s" must be a readable PHP file.', $path));
		}

		foreach ($this->autoload->namespaces(true) as $mapping) {
			$directory = realpath($this->projectDirectory->absolutePath($mapping->path));

			if ($directory === false || ! str_starts_with($file, rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
				continue;
			}

			$relative = substr($file, strlen(rtrim($directory, DIRECTORY_SEPARATOR)) + 1, -4);
			$provider = $mapping->namespace . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

			if (class_exists($provider) && (new ReflectionClass($provider))->getFileName() === $file) {
				return $provider;
			}
		}

		throw new RuntimeException(sprintf('Cannot resolve cli.tooling_provider_path "%s" through the project Composer PSR-4 mappings. Check its namespace and filename, then run composer dump-autoload.', $path));
	}
}
