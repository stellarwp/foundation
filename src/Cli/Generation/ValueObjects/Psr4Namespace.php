<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation\ValueObjects;

/**
 * Represents a Composer PSR-4 namespace root in the target project.
 */
final readonly class Psr4Namespace
{
	public function __construct(
		public string $namespace,
		public string $path
	) {
	}

	public function matches(string $namespace): bool {
		$root = trim($this->namespace, '\\');

		return $namespace === $root || str_starts_with($namespace, $root . '\\');
	}

	public function pathFor(string $namespace): string {
		$root              = trim($this->namespace, '\\');
		$relativeNamespace = '';

		if ($namespace !== $root) {
			$relativeNamespace = trim(substr($namespace, strlen($root)), '\\');
		}

		$base     = trim($this->path, '/');
		$segments = $relativeNamespace === '' ? '' : str_replace('\\', '/', $relativeNamespace);

		if ($base === '') {
			return $segments;
		}

		return $segments === '' ? $base : $base . '/' . $segments;
	}
}
