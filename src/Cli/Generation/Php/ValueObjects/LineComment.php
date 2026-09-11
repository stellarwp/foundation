<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation\Php\ValueObjects;

/**
 * Represents a standalone PHP line comment discovered by the source editor.
 */
final readonly class LineComment
{
	public function __construct(
		public string $indent,
		public int $lineStartOffset,
		public int $commentStartOffset
	) {
	}
}
