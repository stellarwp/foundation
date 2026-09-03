<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation\Php\ValueObjects;

/**
 * Represents a source-code line insertion point with the indentation to use.
 */
final readonly class LineInsertion
{
	public function __construct(
		public int $lineStartOffset,
		public string $indent
	) {
	}
}
