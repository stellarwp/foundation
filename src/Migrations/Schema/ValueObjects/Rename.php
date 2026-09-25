<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema\ValueObjects;

use InvalidArgumentException;

/**
 * An explicit name change, with a table only when renaming a column.
 *
 * @internal
 */
final readonly class Rename
{
	/**
	 * Keep the resolved names used by schema changes and SQL planning.
	 *
	 * @param non-empty-string      $from
	 * @param non-empty-string      $to
	 * @param non-empty-string|null $table
	 *
	 * @throws InvalidArgumentException When the names are empty or equal ignoring case.
	 */
	public function __construct(
		public string $from,
		public string $to,
		public ?string $table = null,
	) {
		if (trim($from) === '' || trim($to) === '' || strcasecmp($from, $to) === 0) {
			throw new InvalidArgumentException('A rename requires two distinct, non-empty names.');
		}
	}
}
