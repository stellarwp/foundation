<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation\ValueObjects;

use RuntimeException;

/**
 * Represents the Strauss namespace prefix configured by the target project.
 */
final readonly class StraussConfig
{
	public function __construct(
		public string $namespacePrefix
	) {
	}

	public function foundationClass(string $class): string {
		if (! str_starts_with($class, 'StellarWP\\Foundation\\')) {
			throw new RuntimeException(sprintf('Cannot apply Foundation namespace prefix to non-Foundation class "%s".', $class));
		}

		return $this->namespacePrefix . $class;
	}
}
