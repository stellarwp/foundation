<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Package;

use RuntimeException;

/**
 * Creates the local file scaffold for a new Foundation split package.
 *
 * Use this before repository creation when a command receives a package name
 * that does not yet exist under `src/`.
 */
final readonly class PackageScaffolder
{
	public function __construct(
		private string $rootPath
	) {
	}

	public function defaultPackageName(string $input): string {
		$component = $this->componentFromInput($input);

		return 'stellarwp/foundation-' . strtolower($component);
	}

	public function defaultDirectory(string $input): string {
		return 'src/' . $this->componentFromInput($input);
	}

	public function create(string $input, string $packageName): PackageScaffold {
		$component   = $this->componentFromInput($input);
		$packageName = $this->normalizePackageName($packageName);
		$path        = $this->rootPath . '/src/' . $component;

		if (file_exists($path) && ! is_dir($path)) {
			throw new RuntimeException(sprintf('Cannot create package scaffold because "%s" already exists and is not a directory.', $path));
		}

		$package = new Package(
			name: $packageName,
			component: $component,
			directory: 'src/' . $component,
			path: $path,
			composerPath: $path . '/composer.json'
		);

		return new PackageScaffold($package, $this->writeFiles($package));
	}

	private function componentFromInput(string $input): string {
		$input = trim($input);
		$input = preg_replace('#^stellarwp/foundation-#i', '', $input) ?? $input;
		$input = preg_replace('#^foundation-#i', '', $input) ?? $input;

		if (preg_match('/[^A-Za-z0-9]/', $input) === 1) {
			$input = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', strtolower($input))));
		}

		$component = preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '';

		if ($component === '') {
			throw new RuntimeException(sprintf('Cannot create package scaffold from invalid package name "%s".', $input));
		}

		return ucfirst($component);
	}

	private function normalizePackageName(string $packageName): string {
		$packageName = strtolower(trim($packageName));

		if (! preg_match('#^stellarwp/foundation-[a-z0-9][a-z0-9-]*$#', $packageName)) {
			throw new RuntimeException(sprintf('Invalid Foundation package name "%s". Expected stellarwp/foundation-<package>.', $packageName));
		}

		return $packageName;
	}

	/**
	 * @return list<string>
	 */
	private function writeFiles(Package $package): array {
		$files = [
			'composer.json'                            => $this->composerJson($package),
			'README.md'                                => $this->readme($package),
			'.gitattributes'                           => $this->gitAttributes(),
			'.gitignore'                               => $this->gitIgnore(),
			'.github/workflows/close-pull-request.yml' => $this->closePullRequestWorkflow(),
		];

		$createdFiles = [];

		foreach ($files as $relativePath => $contents) {
			$path = $package->path . '/' . $relativePath;

			if (file_exists($path)) {
				continue;
			}

			$directory = dirname($path);

			if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
				throw new RuntimeException(sprintf('Could not create directory "%s".', $directory));
			}

			if (file_put_contents($path, $contents) === false) {
				throw new RuntimeException(sprintf('Could not write package scaffold file "%s".', $path));
			}

			$createdFiles[] = $relativePath;
		}

		return $createdFiles;
	}

	private function composerJson(Package $package): string {
		$composer = [
			'name'        => $package->name,
			'type'        => 'library',
			'description' => sprintf('Foundation %s package.', $package->component),
			'license'     => 'GPL-2.0-or-later',
			'config'      => [
				'vendor-dir'        => 'vendor',
				'preferred-install' => 'dist',
			],
			'require'     => [
				'php' => '>=8.3',
			],
			'autoload'    => [
				'psr-4' => [
					sprintf('StellarWP\\Foundation\\%s\\', $package->component) => '',
				],
			],
			'extra'       => [
				'branch-alias' => [
					'dev-main' => '1.1.x-dev',
				],
			],
		];

		return (string) json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
	}

	private function readme(Package $package): string {
		return <<<README
		# Foundation {$package->component}

		> [!WARNING]
		> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

		## Installation

		```shell
		composer require {$package->name}
		```
		README . PHP_EOL;
	}

	private function gitAttributes(): string {
		return <<<'GITATTRIBUTES'
		# Path-based git attributes
		# https://www.kernel.org/pub/software/scm/git/docs/gitattributes.html

		# Ignore paths when git creates an archive of this package
		.gitattributes         export-ignore
		.gitignore             export-ignore
		.github                export-ignore
		GITATTRIBUTES . PHP_EOL;
	}

	private function gitIgnore(): string {
		return <<<'GITIGNORE'
		vendor/
		composer.lock
		GITIGNORE . PHP_EOL;
	}

	private function closePullRequestWorkflow(): string {
		return <<<'WORKFLOW'
		name: Close Pull Request

		on:
		  pull_request_target:
		    types: [opened]

		jobs:
		  run:
		    runs-on: ubuntu-latest
		    steps:
		      - uses: superbrothers/close-pull-request@v3
		        with:
		          comment: "This is a read-only repository. Please submit your PR on the https://github.com/stellarwp/foundation repository.<br><br>Thanks!"
		WORKFLOW . PHP_EOL;
	}
}
