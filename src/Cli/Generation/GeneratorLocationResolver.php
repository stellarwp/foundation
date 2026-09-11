<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation;

use RuntimeException;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ComposerProject;
use StellarWP\Foundation\Cli\Generation\ValueObjects\PhpNamespace;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use StellarWP\Foundation\Container\Contracts\Configuration;

/**
 * Resolves generator namespaces and directories from project conventions and overrides.
 *
 * @internal
 */
final readonly class GeneratorLocationResolver
{
	/**
	 * Receive project paths and namespace configuration.
	 */
	public function __construct(
		private ProjectDirectory $projectDirectory,
		private Configuration $config
	) {
	}

	/**
	 * Choose an explicit namespace, a project setting, or the command's default suffix.
	 *
	 * @throws RuntimeException When the selected namespace or generator configuration is invalid.
	 */
	public function namespaceFor(string $generator, string $defaultNamespace, ComposerProject $project, ?string $namespace = null): string {
		if ($namespace !== null && trim($namespace) !== '') {
			return (new PhpNamespace(trim($namespace, '\\')))->value;
		}

		$generators = $this->config->get('generators', []);

		if (! is_array($generators)) {
			throw new RuntimeException('The "generators" setting in config.php must be an array.');
		}

		if (! array_key_exists($generator, $generators)) {
			return (new PhpNamespace(trim($project->defaultPsr4Namespace()->namespace . $defaultNamespace, '\\')))->value;
		}

		$settings = $generators[$generator];

		if (! is_array($settings) || ! isset($settings['namespace']) || ! is_string($settings['namespace'])) {
			throw new RuntimeException(sprintf('The "generators.%s.namespace" setting in config.php must be a namespace string.', $generator));
		}

		try {
			return (new PhpNamespace(trim($settings['namespace'], '\\')))->value;
		} catch (RuntimeException $exception) {
			throw new RuntimeException(sprintf('Invalid "generators.%s.namespace" in config.php: %s', $generator, $exception->getMessage()), 0, $exception);
		}
	}

	/**
	 * Resolve an explicit output directory or map the namespace through Composer.
	 *
	 * @throws RuntimeException When the namespace has no PSR-4 mapping and no path was supplied.
	 */
	public function directoryFor(string $namespace, ComposerProject $project, ?string $path = null): string {
		if ($path !== null && trim($path) !== '') {
			return $this->projectDirectory->absolutePath($path);
		}

		$autoload = $project->psr4NamespaceFor($namespace);

		if ($autoload === null) {
			throw new RuntimeException(sprintf(
				'Namespace "%s" is outside the Composer PSR-4 namespaces in composer.json. Pass --path to choose an output directory.',
				$namespace
			));
		}

		return $this->projectDirectory->absolutePath($autoload->pathFor($namespace));
	}
}
