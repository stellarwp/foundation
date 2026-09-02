<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table\ValueObjects;

use InvalidArgumentException;

/**
 * Represents a database column comment that is safe to embed in schema SQL.
 */
final readonly class ColumnComment
{
	/**
	 * @throws InvalidArgumentException When the comment requires SQL-mode-dependent escaping.
	 */
	public function __construct(
		public string $comment
	) {
		if (str_contains($this->comment, '\\')) {
			throw new InvalidArgumentException('Database column comments cannot contain backslashes.');
		}

		if (preg_match('/[\x00-\x1F\x7F]/', $this->comment) === 1) {
			throw new InvalidArgumentException('Database column comments cannot contain control characters.');
		}
	}

	/**
	 * Render the comment as a SQL string literal under every MySQL SQL mode.
	 */
	public function sql(): string {
		return "'" . str_replace("'", "''", $this->comment) . "'";
	}
}
