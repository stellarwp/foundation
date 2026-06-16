<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation\ValueObjects;

/**
 * Represents a Composer PSR-4 namespace root in the target project.
 */
final readonly class AutoloadNamespace
{
	public function __construct(
		public string $namespace,
		public string $path
	) {
	}
}
