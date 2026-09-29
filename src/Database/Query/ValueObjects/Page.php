<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query\ValueObjects;

/**
 * A page of query results and its pagination metadata.
 */
final readonly class Page
{
	/**
	 * Capture results calculated by Query::paginate().
	 *
	 * @internal Obtain pages through Query::paginate().
	 *
	 * @param list<array<string, mixed>> $items
	 * @param positive-int               $perPage
	 * @param positive-int               $currentPage
	 */
	public function __construct(
		public array $items,
		public int $total,
		public int $perPage,
		public int $currentPage,
	) {
	}

	/**
	 * Return the final page number, using page one for an empty result.
	 */
	public function lastPage(): int {
		if ($this->total === 0) {
			return 1;
		}

		return intdiv($this->total - 1, $this->perPage) + 1;
	}

	/**
	 * Report whether another page follows the requested page.
	 */
	public function hasMorePages(): bool {
		return $this->currentPage < $this->lastPage();
	}
}
