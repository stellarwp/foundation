<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation;

use RuntimeException;
use StellarWP\Foundation\Cli\Commands\Make\Database\ProviderCommand;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ComposerProject;
use StellarWP\Foundation\Cli\Generation\ValueObjects\PhpNamespace;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use StellarWP\Foundation\Container\Contracts\Configuration;

/**
 * Resolves generator namespaces and directories from project conventions and overrides.
 */
final readonly class GeneratorLocationResolver
{
	/**
	 * @var array<string, string>
	 */
	private array $namespaces;

	/**
	 * Read and validate project settings for registered generators once.
	 *
	 * @param array<string, string> $defaultNamespaces Registered generator keys mapped to namespace suffixes.
	 *
	 * @throws RuntimeException When registered generator configuration contains an invalid namespace or setting.
	 */
	public function __construct(
		private ProjectDirectory $projectDirectory,
		Configuration $config,
		private array $defaultNamespaces
	) {
		$generators = $config->get('generators', []);

		if (! is_array($generators)) {
			throw new RuntimeException('The "generators" setting in foundation/config.php must be an array.');
		}

		$namespaces = [];

		foreach ($generators as $generator => $settings) {
			if (! array_key_exists($generator, $this->defaultNamespaces)) {
				continue;
			}

			if (! is_array($settings) || ! isset($settings['namespace']) || ! is_string($settings['namespace'])) {
				throw new RuntimeException(sprintf('The "generators.%s.namespace" setting in foundation/config.php must be a namespace string.', $generator));
			}

			try {
				$namespaces[$generator] = (new PhpNamespace(trim($settings['namespace'], '\\')))->value;
			} catch (RuntimeException $exception) {
				throw new RuntimeException(sprintf('Invalid "generators.%s.namespace" in foundation/config.php: %s', $generator, $exception->getMessage()), 0, $exception);
			}
		}

		$this->namespaces = $namespaces;
	}

	/**
	 * Choose an explicit namespace, a project setting, or the conventional namespace.
	 *
	 * @param string $generator A key from the registered generator defaults.
	 *
	 * @throws RuntimeException When the explicit namespace is invalid.
	 */
	public function namespaceFor(string $generator, ComposerProject $project, ?string $namespace = null): string {
		if ($namespace !== null && trim($namespace) !== '') {
			return (new PhpNamespace(trim($namespace, '\\')))->value;
		}

		return $this->namespaces[$generator]
			?? trim($project->defaultPsr4Namespace()->namespace, '\\') . '\\' . $this->defaultNamespaces[$generator];
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

	/**
	 * Locate the selected provider file or Provider.php in the configured provider namespace.
	 *
	 * @throws RuntimeException When the provider namespace has no Composer PSR-4 mapping.
	 */
	public function databaseProvider(ComposerProject $project, ?string $path = null): string {
		if ($path !== null && trim($path) !== '') {
			return $this->projectDirectory->absolutePath($path);
		}

		$namespace = $this->namespaceFor(ProviderCommand::CONFIG_KEY, $project);

		try {
			return $this->directoryFor($namespace, $project) . '/Provider.php';
		} catch (RuntimeException $exception) {
			throw new RuntimeException(sprintf(
				'Database provider namespace "%s" has no Composer PSR-4 mapping. Correct "generators.%s.namespace" or its Composer mapping, or pass --provider to select an existing provider file.',
				$namespace,
				ProviderCommand::CONFIG_KEY
			), 0, $exception);
		}
	}
}
