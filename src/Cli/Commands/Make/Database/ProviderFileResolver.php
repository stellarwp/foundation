<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Make\Database;

use RuntimeException;
use StellarWP\Foundation\Cli\Generation\GeneratorLocationResolver;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ComposerProject;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;

/**
 * Locates the database provider used by table and migration generators.
 *
 * @internal
 */
final readonly class ProviderFileResolver
{
	/**
	 * Receive project paths and configurable generator locations.
	 */
	public function __construct(
		private ProjectDirectory $projectDirectory,
		private GeneratorLocationResolver $locations
	) {
	}

	/**
	 * Locate the selected provider file or Provider.php in the configured provider namespace.
	 *
	 * @throws RuntimeException When the provider namespace has no Composer PSR-4 mapping.
	 */
	public function resolve(ComposerProject $project, ?string $path = null): string {
		if ($path !== null && trim($path) !== '') {
			return $this->projectDirectory->absolutePath($path);
		}

		$namespace = $this->locations->namespaceFor(ProviderCommand::CONFIG_KEY, ProviderCommand::DEFAULT_NAMESPACE, $project);

		try {
			return $this->locations->directoryFor($namespace, $project) . '/Provider.php';
		} catch (RuntimeException $exception) {
			throw new RuntimeException(sprintf(
				'Database provider namespace "%s" has no Composer PSR-4 mapping. Correct "generators.%s.namespace" or its Composer mapping, or pass --provider to select an existing provider file.',
				$namespace,
				ProviderCommand::CONFIG_KEY
			), 0, $exception);
		}
	}
}
