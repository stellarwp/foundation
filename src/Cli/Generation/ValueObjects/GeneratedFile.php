<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation\ValueObjects;

/**
 * Value object describing a generated file before it is written.
 */
final readonly class GeneratedFile
{
	public function __construct(
		public string $path,
		public string $relativePath,
		public string $contents
	) {
	}
}
