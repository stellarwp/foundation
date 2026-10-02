<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Query\ValueObjects\Fragment;
use StellarWP\Foundation\Database\Query\ValueObjects\Selection;

/**
 * Validate and quote query identifiers independently of bound values.
 *
 * @internal
 */
final readonly class IdentifierQuoter
{
	/**
	 * Quote a single identifier, including existing physical names starting with digits.
	 *
	 * @throws InvalidArgumentException When the identifier contains SQL or is empty.
	 */
	public function quote(string $name): string {
		if (preg_match('/\A[A-Za-z0-9_]+\z/', $name) !== 1) {
			throw new InvalidArgumentException('Expected an identifier, received: ' . $name);
		}

		return '`' . $name . '`';
	}

	/**
	 * Quote a column reference, optionally allowing a wildcard in a selection.
	 *
	 * @throws InvalidArgumentException When the column reference is invalid.
	 */
	public function column(string $name, bool $wildcard = false): string {
		$parts = explode('.', $name);

		if (count($parts) > 2) {
			throw new InvalidArgumentException('Use column or qualifier.column: ' . $name);
		}

		$quoted = [];

		foreach ($parts as $position => $part) {
			$quoted[] = $wildcard && $part === '*' && $position === count($parts) - 1
				? '*'
				: $this->quote($part);
		}

		return implode('.', $quoted);
	}

	/**
	 * Quote a selected column and its optional output alias.
	 *
	 * @throws InvalidArgumentException When the column or its output alias is invalid.
	 */
	public function selection(string $name): Selection {
		$parts = preg_split('/\s+as\s+/i', $name, 2);

		if ($parts === false) {
			throw new InvalidArgumentException('Invalid selection: ' . $name);
		}

		$wildcard = $parts[0] === '*' || str_ends_with($parts[0], '.*');

		if (isset($parts[1]) && $wildcard) {
			throw new InvalidArgumentException('A wildcard selection cannot have an output alias.');
		}

		$sql    = $this->column($parts[0], true) . (isset($parts[1]) ? ' AS ' . $this->quote($parts[1]) : '');
		$column = explode('.', $parts[0]);
		$output = $parts[1] ?? $column[count($column) - 1];

		return new Selection(new Fragment($sql), strtolower($output), $wildcard);
	}
}
