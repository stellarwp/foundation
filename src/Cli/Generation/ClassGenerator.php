<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation;

use RuntimeException;
use StellarWP\Foundation\Cli\Composer\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\ValueObjects\GeneratedFile;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;

/**
 * Coordinates source generation for single-class project generators.
 *
 * @internal
 */
final readonly class ClassGenerator
{
	/**
	 * Receive the collaborators used to resolve, render, and write a class.
	 */
	public function __construct(
		private ProjectDirectory $projectDirectory,
		private ComposerAutoloadResolver $autoload,
		private GeneratorLocationResolver $locations,
		private WordPressClassNameResolver $classNames,
		private StubRenderer $stubs,
		private GeneratedFileWriter $files
	) {
	}

	/**
	 * Create a class using command defaults and project or explicit overrides.
	 *
	 * @throws RuntimeException When generation input is invalid or the file cannot be created.
	 */
	public function generate(string $name, string $key, string $defaultNamespace, string $stub, ?string $namespace = null, ?string $path = null): GeneratedFile {
		$project   = $this->autoload->project();
		$class     = $this->classNames->className($name);
		$namespace = $this->locations->namespaceFor($key, $defaultNamespace, $project, $namespace);
		$directory = $this->locations->directoryFor($namespace, $project, $path);
		$filePath  = $directory . '/' . $class . '.php';
		$file      = new GeneratedFile(
			path: $filePath,
			relativePath: $this->projectDirectory->relativePath($filePath),
			contents: $this->stubs->render($this->projectDirectory->absolutePath($stub), [
				'namespace' => $namespace,
				'class'     => $class,
			])
		);

		$this->files->write($file);

		return $file;
	}
}
